
import os
import re
import json
import sqlite3
import math
import logging
from collections import Counter
from datetime import datetime
import io

# libraries for processing
import fitz  # PyMuPDF
import pytesseract
from PIL import Image
from docx import Document
import cv2
import numpy as np

# Configure Logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("CheqMatePackage")

# ==========================
# 1. Document Processor
# ==========================
class DocumentProcessor:
    def __init__(self):
        # Optional: Set tesseract path if needed
        # pytesseract.pytesseract.tesseract_cmd = r'C:\Program Files\Tesseract-OCR\tesseract.exe'
        pass

    def extract_text(self, file_obj, filename: str) -> str:
        """
        Extracts text from a file object (bytes) or path.
        In Streamlit, uploaded_file is a BytesIO-like object.
        """
        ext = os.path.splitext(filename)[1].lower()
        
        try:
            # If it's a file path
            if isinstance(file_obj, str) and os.path.exists(file_obj):
                with open(file_obj, 'rb') as f:
                    content = f.read()
            # If it's a Streamlit UploadedFile or BytesIO
            else:
                file_obj.seek(0)
                content = file_obj.read()

            if ext == '.pdf':
                return self._process_pdf(content)
            elif ext in ['.docx', '.doc']:
                return self._process_docx(content)
            elif ext in ['.png', '.jpg', '.jpeg', '.tiff', '.bmp']:
                return self._process_image(content)
            elif ext == '.txt':
                return content.decode('utf-8', errors='ignore')
            else:
                return ""
        except Exception as e:
            logger.error(f"Error processing {filename}: {e}")
            return ""

    def _process_docx(self, content: bytes) -> str:
        doc = Document(io.BytesIO(content))
        return "\n".join([para.text for para in doc.paragraphs])

    def _process_image(self, content: bytes) -> str:
        # Convert bytes to numpy array
        nparr = np.frombuffer(content, np.uint8)
        image = cv2.imdecode(nparr, cv2.IMREAD_COLOR)
        processed_img = self._preprocess_image(image)
        text = pytesseract.image_to_string(processed_img)
        return text

    def _process_pdf(self, content: bytes) -> str:
        doc = fitz.open(stream=content, filetype="pdf")
        full_text = []

        for page_num, page in enumerate(doc):
            text = page.get_text()
            if text.strip():
                full_text.append(text)
            
            # Scanned PDF check
            if len(text.strip()) < 50:
                pix = page.get_pixmap()
                img_data = pix.tobytes("png")
                nparr = np.frombuffer(img_data, np.uint8)
                img = cv2.imdecode(nparr, cv2.IMREAD_COLOR)
                ocr_text = pytesseract.image_to_string(self._preprocess_image(img))
                full_text.append(ocr_text)
                
        return "\n".join(full_text)

    def _preprocess_image(self, image):
        gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
        _, thresh = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
        return thresh


# ==========================
# 2. AI Detector
# ==========================
class AIDetector:
    def calculate_burstiness(self, text):
        sentences = re.split(r'[.!?]+', text)
        sentences = [s.strip() for s in sentences if len(s.strip()) > 5]
        if not sentences:
            return 0

        lengths = [len(s.split()) for s in sentences]
        mean_len = sum(lengths) / len(lengths)
        if mean_len == 0: return 0
        
        variance = sum([(l - mean_len)**2 for l in lengths]) / len(lengths)
        std_dev = math.sqrt(variance)
        
        return std_dev / mean_len

    def detect(self, text):
        if len(text) < 100:
            return 0.0

        burstiness = self.calculate_burstiness(text)
        # Invert burstiness to get "AI Probability" (Low burstiness = High AI prob)
        ai_prob = max(0.0, min(100.0, (1.0 - burstiness) * 100))
        return round(ai_prob, 2)


# ==========================
# 3. Plagiarism Detector
# ==========================
class PlagiarismDetector:
    def __init__(self):
        self.k_gram_len = 5

    def preprocess(self, text):
        text = text.lower()
        text = re.sub(r'[^a-z0-9\s]', '', text)
        text = re.sub(r'\s+', ' ', text).strip()
        return text

    def get_shingles(self, text):
        words = self.preprocess(text).split()
        if len(words) < self.k_gram_len:
            return set()
        
        shingles = set()
        for i in range(len(words) - self.k_gram_len + 1):
            shingle = tuple(words[i : i + self.k_gram_len])
            shingles.add(hash(shingle))
        return shingles

    def calculate_similarity(self, shingles_a, shingles_b):
        if not shingles_a or not shingles_b:
            return 0.0
        
        intersection = len(shingles_a.intersection(shingles_b))
        union = len(shingles_a.union(shingles_b))
        
        return (intersection / union) * 100 if union > 0 else 0.0


# ==========================
# 4. Storage & Manager
# ==========================
DB_PATH = "cheqmate_proto.db"

class CheqMateEngine:
    def __init__(self):
        self.processor = DocumentProcessor()
        self.ai_detector = AIDetector()
        self.plag_detector = PlagiarismDetector()
        
        self.conn = sqlite3.connect(DB_PATH, check_same_thread=False)
        self.create_tables()

    def create_tables(self):
        cursor = self.conn.cursor()
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS submissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                filename TEXT,
                hashes TEXT,
                ai_score REAL,
                max_plag_score REAL DEFAULT 0,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ''')
        self.conn.commit()

    def process_submission(self, file_obj, filename: str):
        # 1. Extract Text
        text = self.processor.extract_text(file_obj, filename)
        if not text:
            return {"error": "Could not extract text"}

        # 2. AI Detection
        ai_score = self.ai_detector.detect(text)

        # 3. Plagiarism Check
        current_shingles = self.plag_detector.get_shingles(text)
        
        # Get all previous submissions to compare
        cursor = self.conn.cursor()
        cursor.execute("SELECT id, filename, hashes FROM submissions")
        rows = cursor.fetchall()
        
        max_plag_score = 0.0
        details = []

        for row in rows:
            other_id, other_name, other_hashes_json = row
            # Skip if same filename (re-upload logic handled by save overwriting, but for check we might see old version if not careful. 
            # Ideally we check duplicate filenames before saving, but here we just compare.)
            if other_name == filename:
                continue

            other_shingles = set(json.loads(other_hashes_json))
            score = self.plag_detector.calculate_similarity(current_shingles, other_shingles)
            
            if score > 0:
                details.append({
                    "filename": other_name,
                    "score": round(score, 2)
                })
            if score > max_plag_score:
                max_plag_score = score

        # 4. Save to DB
        # Store shingles as list for JSON
        shingles_list = list(current_shingles)
        
        # Check if exists to update or insert
        cursor.execute("SELECT id FROM submissions WHERE filename = ?", (filename,))
        existing = cursor.fetchone()
        
        if existing:
            cursor.execute('''
                UPDATE submissions 
                SET hashes = ?, ai_score = ?, max_plag_score = ?, timestamp = CURRENT_TIMESTAMP 
                WHERE filename = ?
            ''', (json.dumps(shingles_list), ai_score, max_plag_score, filename))
        else:
            cursor.execute('''
                INSERT INTO submissions (filename, hashes, ai_score, max_plag_score)
                VALUES (?, ?, ?, ?)
            ''', (filename, json.dumps(shingles_list), ai_score, max_plag_score))
        
        self.conn.commit()
        
        # Sort details by score desc
        details.sort(key=lambda x: x['score'], reverse=True)

        return {
            "filename": filename,
            "ai_score": ai_score,
            "plagiarism_score": round(max_plag_score, 2),
            "details": details,
            "text_preview": text[:200] + "..."
        }

    def get_all_submissions(self):
        cursor = self.conn.cursor()
        cursor.execute("SELECT filename, ai_score, max_plag_score, timestamp FROM submissions ORDER BY max_plag_score DESC")
        return cursor.fetchall()

    def get_leaderboard_data(self):
        """Returns list of dicts for dataframe"""
        data = self.get_all_submissions()
        return [
            {"Filename": r[0], "AI Probability": r[1], "Plagiarism Score": r[2], "Timestamp": r[3]}
            for r in data
        ]

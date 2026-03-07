import fitz  # PyMuPDF
import pytesseract
from PIL import Image
from docx import Document
import io
import os
import cv2
import numpy as np
import logging

# Configure Logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

class DocumentProcessor:
    def __init__(self):
        # Allow overriding tesseract path via env var if needed
        # On Windows, try to find it, but don't hard crash if Linux
        if os.name == 'nt':
            paths = [
                r'C:\Program Files\Tesseract-OCR\tesseract.exe',
                r'C:\Program Files (x86)\Tesseract-OCR\tesseract.exe',
                os.path.expandvars(r'%LOCALAPPDATA%\Tesseract-OCR\tesseract.exe')
            ]
            for p in paths:
                if os.path.exists(p):
                    pytesseract.pytesseract.tesseract_cmd = p
                    break

    def extract_text(self, file_path: str) -> str:
        """
        Main entry point. Detects file type and routes to appropriate extractor.
        """
        ext = os.path.splitext(file_path)[1].lower()
        
        try:
            if ext == '.pdf':
                return self._process_pdf(file_path)
            elif ext in ['.docx', '.doc']:
                return self._process_docx(file_path)
            elif ext in ['.png', '.jpg', '.jpeg', '.tiff', '.bmp']:
                return self._process_image(file_path)
            elif ext == '.txt':
                with open(file_path, 'r', encoding='utf-8') as f:
                    return f.read()
            else:
                return ""
        except Exception as e:
            logger.error(f"Error processing {file_path}: {e}")
            return ""

    def _process_docx(self, file_path: str) -> str:
        doc = Document(file_path)
        return "\n".join([para.text for para in doc.paragraphs])

    def _process_image(self, file_path: str) -> str:
        # Load image
        image = cv2.imread(file_path)
        # Preprocess
        processed_img = self._preprocess_image(image)
        # OCR
        text = pytesseract.image_to_string(processed_img)
        return text

    def _process_pdf(self, file_path: str) -> str:
        doc = fitz.open(file_path)
        full_text = []

        for page_num, page in enumerate(doc):
            # 1. Try to extract plain text
            text = page.get_text()
            if text.strip():
                full_text.append(text)
            
            # 2. Extract and OCR all embedded images
            image_list = page.get_images(full=True)
            for img_index, img in enumerate(image_list):
                try:
                    xref = img[0]
                    base_image = doc.extract_image(xref)
                    image_bytes = base_image["image"]
                    
                    # Convert to numpy array for cv2
                    nparr = np.frombuffer(image_bytes, np.uint8)
                    cv_img = cv2.imdecode(nparr, cv2.IMREAD_COLOR)
                    
                    if cv_img is not None:
                        ocr_text = pytesseract.image_to_string(self._preprocess_image(cv_img))
                        if ocr_text.strip():
                            full_text.append(ocr_text)
                except Exception as img_err:
                    logger.warning(f"Failed to extract/OCR embedded image on page {page_num}: {img_err}")
            
            # 3. If there was basically NO text AND NO embedded images, it might be a flat scanned page.
            if len(text.strip()) < 50 and not image_list:
                logger.info(f"Page {page_num} seems entirely scanned with no explicit image objects. Rendering whole page.")
                pix = page.get_pixmap()
                img_data = pix.tobytes("png")
                nparr = np.frombuffer(img_data, np.uint8)
                img = cv2.imdecode(nparr, cv2.IMREAD_COLOR)
                
                try:
                    ocr_text = pytesseract.image_to_string(self._preprocess_image(img))
                    full_text.append(ocr_text)
                except Exception as ocr_err:
                    logger.warning(f"OCR Failed for rendered page {page_num}: {ocr_err}")
                    full_text.append("[OCR Failed - Scanned Image Detected]")
                
        return "\n".join(full_text)

    def _preprocess_image(self, image):
        """
        Applies grayscale, thresholding, and noise removal to improve OCR.
        """
        gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
        
        # Binary thresholding (Otsu's method)
        _, thresh = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
        
        # Denoising (optional, can be slow)
        # denoised = cv2.fastNlMeansDenoising(thresh, None, 10, 7, 21)
        
        return thresh

if __name__ == "__main__":
    # Test
    proc = DocumentProcessor()
    # print(proc.extract_text("test.pdf"))

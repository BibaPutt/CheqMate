from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
import uvicorn
import os
import logging
from processor import DocumentProcessor
from detector import PlagiarismDetector
from ai_detector import AIDetector
from storage import Storage

# Logging Setup
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("CheqMate")

app = FastAPI(title="CheqMate Engine", version="0.1.0")

# Initialize Modules
processor = DocumentProcessor()
detector = PlagiarismDetector()
ai_detector = AIDetector()
storage = Storage()

class SubmissionRequest(BaseModel):
    file_path: str
    submission_id: int
    context_id: int

@app.get("/")
def health_check():
    return {"status": "ok", "service": "CheqMate Engine"}

@app.post("/analyze")
async def analyze_submission(request: SubmissionRequest):
    """
    Main endpoint directly called by Moodle.
    """
    logger.info(f"Received request: {request}")

    if not os.path.exists(request.file_path):
        # In a real shared environment, paths might need mapping.
        # Check if we need to decode URI or handles spaces
        if os.path.exists(request.file_path.replace('%20', ' ')):
             request.file_path = request.file_path.replace('%20', ' ')
        else:
             raise HTTPException(status_code=404, detail=f"File not found on server: {request.file_path}")

    try:
        # 1. Text Extraction
        text = processor.extract_text(request.file_path)
        if not text:
             logger.warning("No text extracted from file.")
             text = "" 
        
        # 2. Plagiarism Check
        shingles = detector.get_shingles(text)
        
        # Get peers (same context/assignment)
        peers = storage.get_all_fingerprints(request.submission_id, request.context_id)
        
        plag_score, details = detector.check_plagiarism(shingles, peers)
        
        # 3. AI Detection
        ai_prob = ai_detector.detect(text)
        
        # 4. Save Fingerprint (for future checks)
        # Note: In a real system you might only save AFTER the deadline or if 'allowed'.
        # For now, we save immediately to allow checking against early submitters.
        # We convert hashes to list for JSON serialization
        storage.save_fingerprint(request.submission_id, request.context_id, list(shingles))
        
        logger.info(f"Analysis Complete. SubID: {request.submission_id}, Plag: {plag_score}%, AI: {ai_prob}%")
        
        # 5. Append Report to File
        try:
            from reporter import append_report_to_pdf, append_report_to_docx
            
            report_lines = [
                f"CheqMate Analysis Report",
                f"--------------------------------------------------",
                f"Plagiarism Score: {round(plag_score, 2)}%",
                f"AI Probability:   {ai_prob}%",
                f"",
                f"Matches found:"
            ]
            
            if details:
                for match in details:
                    report_lines.append(f" - Submission ID: {match['submission_id']} (Similarity: {round(match['score'], 2)}%)")
            else:
                report_lines.append(" - No significant matches found.")
            
            report_text = "\n".join(report_lines)
            
            ext = os.path.splitext(request.file_path)[1].lower()
            if ext == '.pdf':
                append_report_to_pdf(request.file_path, report_text)
                logger.info("Appended report to PDF.")
            elif ext in ['.docx', '.doc']:
                append_report_to_docx(request.file_path, report_text)
                logger.info("Appended report to DOCX.")
                
        except Exception as report_err:
            logger.error(f"Failed to append report to file: {report_err}")

        return {
            "status": "processed",
            "plagiarism_score": round(plag_score, 2),
            "ai_probability": ai_prob,
            "details": details,
            "message": "Analysis successful"
        }

    except Exception as e:
        logger.error(f"Analysis Failed: {e}")
        return {
            "status": "error",
            "message": str(e)
        }

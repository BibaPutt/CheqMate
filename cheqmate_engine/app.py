from fastapi import FastAPI, HTTPException, File, UploadFile, Form
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from typing import Optional, List
import uvicorn
import os
import json
import hashlib
import logging
import shutil
from processor import DocumentProcessor
from detector import PlagiarismDetector
from ai_detector import AIDetector
from storage import Storage

# Logging Setup
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("CheqMate")

app = FastAPI(title="CheqMate Engine", version="1.1.0")

# CORS for Moodle integration
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Initialize Modules (singletons for performance)
processor = DocumentProcessor()
detector = PlagiarismDetector()
ai_detector = AIDetector()
storage = Storage()

# Temp directory for file processing
TEMP_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "temp")
os.makedirs(TEMP_DIR, exist_ok=True)

class SubmissionRequest(BaseModel):
    file_path: str
    submission_id: int
    context_id: int
    assignment_id: Optional[int] = None  # For peer comparison within same assignment
    course_id: Optional[int] = None  # For global source comparison
    check_global_source: Optional[bool] = False
    enable_peer_comparison: Optional[bool] = True
    skip_patterns: Optional[List[str]] = None  # Sections to skip (aim, code, etc.)

class ClearCacheRequest(BaseModel):
    assignment_id: int

class GlobalSourceRequest(BaseModel):
    course_id: int
    file_path: str
    filename: str

@app.get("/")
def health_check():
    """Basic health check"""
    return {"status": "ok", "service": "CheqMate Engine", "version": "1.1.0"}

@app.get("/health")
def detailed_health():
    """Detailed health check with database stats"""
    try:
        fingerprint_count = storage.get_fingerprint_count()
        return {
            "status": "ok",
            "service": "CheqMate Engine",
            "version": "1.1.0",
            "database": {
                "status": "connected",
                "total_fingerprints": fingerprint_count
            }
        }
    except Exception as e:
        return {
            "status": "degraded",
            "error": str(e)
        }

@app.post("/analyze")
async def analyze_submission(request: SubmissionRequest):
    """
    Main endpoint for analyzing submissions.
    Called by Moodle plugin for plagiarism and AI detection.
    """
    logger.info(f"Received request: submission_id={request.submission_id}, assignment_id={request.assignment_id}")

    # Validate file path
    file_path = request.file_path
    if not os.path.exists(file_path):
        # Try URL decoding
        decoded_path = file_path.replace('%20', ' ')
        if os.path.exists(decoded_path):
            file_path = decoded_path
        else:
            raise HTTPException(status_code=404, detail=f"File not found: {request.file_path}")

    try:
        # 1. Text Extraction
        text = processor.extract_text(file_path)
        if not text:
            logger.warning("No text extracted from file.")
            text = ""
        
        # 2. Generate shingles (with skip patterns for excluding sections)
        shingles = detector.get_shingles(text, request.skip_patterns)
        
        # 3. Plagiarism Check
        plag_score = 0.0
        details = []
        
        # 3a. Peer comparison (if enabled)
        if request.enable_peer_comparison:
            peers = storage.get_all_fingerprints(
                request.submission_id, 
                context_id=request.context_id,
                assignment_id=request.assignment_id  # Scoped to same assignment
            )
            
            # 3b. Global source comparison (if enabled)
            global_sources = None
            if request.check_global_source and request.course_id:
                global_sources = storage.get_global_sources(request.course_id)
            
            plag_score, details = detector.check_plagiarism(shingles, peers, global_sources)
        else:
            # Only check global source if peer comparison disabled
            if request.check_global_source and request.course_id:
                global_sources = storage.get_global_sources(request.course_id)
                if global_sources:
                    plag_score, details = detector.check_plagiarism(shingles, [], global_sources)
        
        # 4. AI Detection
        ai_prob = ai_detector.detect(text)
        
        # 5. Save Fingerprint for future comparisons
        storage.save_fingerprint(
            request.submission_id, 
            request.context_id, 
            shingles,
            assignment_id=request.assignment_id
        )
        
        logger.info(f"Analysis Complete. SubID: {request.submission_id}, Plag: {plag_score}%, AI: {ai_prob}%")
        
        # 6. Append Report to File (if supported format)
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
                    if match.get('source_type') == 'global':
                        report_lines.append(f" - Global Source '{match.get('filename', 'Unknown')}': {round(match['score'], 2)}%")
                    else:
                        report_lines.append(f" - Submission ID: {match.get('submission_id')} (Similarity: {round(match['score'], 2)}%)")
            else:
                report_lines.append(" - No significant matches found.")
            
            report_text = "\n".join(report_lines)
            
            ext = os.path.splitext(file_path)[1].lower()
            if ext == '.pdf':
                append_report_to_pdf(file_path, report_text)
                logger.info("Appended report to PDF.")
            elif ext in ['.docx', '.doc']:
                append_report_to_docx(file_path, report_text)
                logger.info("Appended report to DOCX.")
                
        except Exception as report_err:
            logger.error(f"Failed to append report to file: {report_err}")

        return {
            "status": "processed",
            "plagiarism_score": round(plag_score, 2),
            "ai_probability": ai_prob,
            "details": details,
            "peer_comparison_enabled": request.enable_peer_comparison,
            "global_source_checked": request.check_global_source,
            "message": "Analysis successful"
        }

    except Exception as e:
        logger.error(f"Analysis Failed: {e}")
        return {
            "status": "error",
            "plagiarism_score": 0,
            "ai_probability": 0,
            "message": str(e)
        }


@app.post("/global-source/upload")
async def upload_global_source(request: GlobalSourceRequest):
    """
    Upload a global source document for comparison.
    Called when teacher uploads reference documents in course settings.
    """
    logger.info(f"Uploading global source: {request.filename} for course {request.course_id}")
    
    if not os.path.exists(request.file_path):
        raise HTTPException(status_code=404, detail=f"File not found: {request.file_path}")
    
    try:
        # Extract text
        text = processor.extract_text(request.file_path)
        if not text:
            raise HTTPException(status_code=400, detail="Could not extract text from file")
        
        # Generate shingles
        shingles = detector.get_shingles(text)
        
        # Generate content hash for deduplication
        content_hash = hashlib.md5(text.encode()).hexdigest()
        
        # Save to database
        saved = storage.save_global_source(
            request.course_id,
            request.filename,
            content_hash,
            shingles
        )
        
        if saved:
            return {
                "status": "success",
                "message": f"Global source '{request.filename}' uploaded successfully"
            }
        else:
            return {
                "status": "exists",
                "message": f"Global source '{request.filename}' already exists"
            }
            
    except Exception as e:
        logger.error(f"Failed to upload global source: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@app.get("/global-source/{course_id}")
async def list_global_sources(course_id: int):
    """List all global sources for a course"""
    sources = storage.get_global_sources(course_id)
    return {
        "course_id": course_id,
        "count": len(sources),
        "sources": [{"filename": s["filename"]} for s in sources]
    }


@app.delete("/global-source/{course_id}")
async def delete_global_sources(course_id: int, filename: Optional[str] = None):
    """Delete global source(s) for a course"""
    try:
        deleted = storage.delete_global_source(course_id, filename)
        return {
            "status": "success",
            "deleted_count": deleted,
            "message": f"Deleted {deleted} global source(s)"
        }
    except Exception as e:
        logger.error(f"Failed to delete global source: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@app.post("/cache/clear")
async def clear_cache(request: ClearCacheRequest):
    """
    Clear plagiarism cache for an assignment.
    Does NOT delete submission data, only fingerprints.
    """
    logger.info(f"Clearing cache for assignment {request.assignment_id}")
    
    try:
        deleted = storage.clear_assignment_cache(request.assignment_id)
        return {
            "status": "success",
            "assignment_id": request.assignment_id,
            "cleared_count": deleted,
            "message": f"Cleared {deleted} fingerprints"
        }
    except Exception as e:
        logger.error(f"Failed to clear cache: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@app.get("/cache/stats/{assignment_id}")
async def cache_stats(assignment_id: int):
    """Get cache statistics for an assignment"""
    count = storage.get_fingerprint_count(assignment_id)
    return {
        "assignment_id": assignment_id,
        "fingerprint_count": count
    }


@app.delete("/fingerprint/{submission_id}")
async def delete_fingerprint(submission_id: int):
    """
    Delete fingerprint for a specific submission.
    Called by Moodle plugin when a file is deleted from a submission.
    """
    logger.info(f"Deleting fingerprint for submission {submission_id}")
    try:
        deleted = storage.delete_fingerprint(submission_id)
        return {
            "status": "success",
            "submission_id": submission_id,
            "deleted_count": deleted,
            "message": f"Deleted {deleted} fingerprint(s)"
        }
    except Exception as e:
        logger.error(f"Failed to delete fingerprint: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@app.get("/ai-analysis")
async def get_ai_analysis(text: str):
    """Get detailed AI analysis breakdown (for debugging/display)"""
    if len(text) < 100:
        raise HTTPException(status_code=400, detail="Text too short for analysis (min 100 chars)")
    
    analysis = ai_detector.get_detailed_analysis(text)
    return analysis


if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8000)

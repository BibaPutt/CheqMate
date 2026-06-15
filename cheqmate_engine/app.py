from fastapi import FastAPI, HTTPException, File, UploadFile, Form
from fastapi.responses import FileResponse
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from typing import Optional, List
import uvicorn
import os
import json
import hashlib
import logging
import shutil
import re
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

# Load stop words from stop_words_english.txt
STOP_WORDS_PATH = os.path.join(
    os.path.dirname(os.path.abspath(__file__)),
    "stop_words_english.txt"
)
stop_words = set()
try:
    if os.path.exists(STOP_WORDS_PATH):
        with open(STOP_WORDS_PATH, "r", encoding="utf-8") as f:
            for line in f:
                word = line.strip().lower()
                if word:
                    stop_words.add(word)
        logger.info(f"Loaded {len(stop_words)} stop words from {STOP_WORDS_PATH}")
    else:
        logger.warning(f"Stop words file not found at {STOP_WORDS_PATH}")
except Exception as e:
    logger.error(f"Failed to load stop words: {e}")

# Temp directory for file processing
TEMP_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "temp")
os.makedirs(TEMP_DIR, exist_ok=True)

# Permanent directory for global source documents
GLOBAL_SOURCES_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "global_sources")
os.makedirs(GLOBAL_SOURCES_DIR, exist_ok=True)

def resolve_moodledata_path(path: str, dataroot: Optional[str] = None) -> str:
    path = path.replace("\\", "/")
    logger.info(f"resolve_moodledata_path input: {path}, dataroot: {dataroot}")
    
    # If /moodledata directory exists (e.g. running in Docker container), translate path
    if os.path.exists('/moodledata') and dataroot:
        dataroot_normalized = dataroot.replace("\\", "/")
        if path.startswith(dataroot_normalized):
            new_path = path.replace(dataroot_normalized, '/moodledata', 1)
            logger.info(f"Translated to Docker path: {new_path}")
            return new_path
            
    # Otherwise, Moodle and the engine are on the same machine locally, so we use the path as is
    return path

class SetGradingRequest(BaseModel):
    course_id: int
    filename: str

class SubmissionRequest(BaseModel):
    file_path: str
    dataroot: Optional[str] = None
    submission_id: int
    context_id: int
    assignment_id: Optional[int] = None  # For peer comparison within same assignment
    course_id: Optional[int] = None  # For global source comparison
    check_global_source: Optional[bool] = False
    enable_peer_comparison: Optional[bool] = True
    skip_patterns: Optional[List[str]] = None  # Sections to skip (aim, code, etc.)
    file_content: Optional[str] = None  # Base64 encoded file content
    section_tag: Optional[str] = None  # Optional specific section/experiment to grade against
    grading_strictness: Optional[int] = 50

class ClearCacheRequest(BaseModel):
    assignment_id: int

class GlobalSourceRequest(BaseModel):
    course_id: int
    file_path: str
    dataroot: Optional[str] = None
    filename: str
    file_content: Optional[str] = None  # Base64 encoded file content
    sections: Optional[str] = None  # Optional predefined sections JSON string

class UpdateSectionsRequest(BaseModel):
    course_id: int
    filename: str
    sections: str  # JSON string of sections

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

    local_file_path = None
    def cleanup():
        nonlocal local_file_path
        if local_file_path and os.path.exists(local_file_path):
            try:
                os.remove(local_file_path)
                logger.info(f"Cleaned up local temp file: {local_file_path}")
            except Exception as cleanup_err:
                logger.error(f"Failed to cleanup local temp file {local_file_path}: {cleanup_err}")

    if request.file_content:
        try:
            import base64
            content_bytes = base64.b64decode(request.file_content)
            filename = os.path.basename(request.file_path)
            safe_filename = "".join(c for c in filename if c.isalnum() or c in "._- ")
            local_file_path = os.path.join(TEMP_DIR, f"sub_{request.submission_id}_{hashlib.md5(request.file_path.encode()).hexdigest()}_{safe_filename}")
            with open(local_file_path, "wb") as f:
                f.write(content_bytes)
            file_path = local_file_path
        except Exception as e:
            logger.error(f"Failed to decode and save submission file content: {e}")
            raise HTTPException(status_code=400, detail=f"Invalid file content: {e}")
    else:
        # Resolve path dynamically
        file_path = resolve_moodledata_path(request.file_path, request.dataroot)

    logger.info(f"Checking file: {file_path}")

    # Validate file exists
    if not os.path.exists(file_path):
        logger.error("====================================")
        logger.error(f"FILE NOT FOUND: {file_path}")
        logger.error("====================================")
        raise HTTPException(status_code=404, detail=f"File not found: {file_path}")

    try:
        # 1️⃣ Extract Text
        text = processor.extract_text(file_path)
        if not text:
            logger.warning("No text extracted from file.")
            text = ""

        shingles = detector.get_shingles(text, request.skip_patterns)

        plag_score = 0.0
        details = []

        if request.enable_peer_comparison:
            peers = storage.get_all_fingerprints(
                request.submission_id,
                context_id=request.context_id,
                assignment_id=request.assignment_id
            )

            global_sources = None
            if request.check_global_source and request.course_id:
                global_sources = storage.get_global_sources(request.course_id)

            plag_score, details = detector.check_plagiarism(shingles, peers, global_sources)

        else:
            if request.check_global_source and request.course_id:
                global_sources = storage.get_global_sources(request.course_id)
                if global_sources:
                    plag_score, details = detector.check_plagiarism(shingles, [], global_sources)

        # 4️⃣ AI Detection
        ai_prob = ai_detector.detect(text)
        

        # 5️⃣ Save Fingerprint
        storage.save_fingerprint(
            request.submission_id,
            request.context_id,
            shingles,
            assignment_id=request.assignment_id
        )

        logger.info(f"Analysis Complete. Plag: {plag_score}%, AI: {ai_prob}%")

        # 5.5️⃣ Auto Grading Metric Calculations
        topic_knowledge_score = 3.0
        lab_performance_score = 3.0
        
        if request.course_id:
            grading_source = storage.get_grading_global_source(request.course_id)
            if grading_source:
                logger.info(f"Found grading global source: {grading_source['filename']}")
                
                # Default values (full manual)
                grading_full_text = grading_source["full_text"] or ""
                expected_images = grading_source["image_count"] or 0
                
                # Check for specific section/experiment page range slicing
                sections = []
                if grading_source.get("sections"):
                    try:
                        sections = json.loads(grading_source["sections"])
                    except Exception as e:
                        logger.error(f"Failed to parse sections JSON: {e}")
                        
                target_section = None
                if request.section_tag and sections:
                    for sec in sections:
                        if sec.get("tag") == request.section_tag:
                            target_section = sec
                            break
                            
                if target_section:
                    start_page = target_section.get("start_page", 1)
                    end_page = target_section.get("end_page", 1)
                    logger.info(f"Slicing global source for section '{request.section_tag}': pages {start_page} to {end_page}")
                    
                    permanent_filename = f"{request.course_id}_{grading_source['filename']}"
                    permanent_path = os.path.join(GLOBAL_SOURCES_DIR, permanent_filename)
                    
                    if os.path.exists(permanent_path):
                        # Extract reference text and images from only this section
                        section_text = processor.extract_text_from_pages(permanent_path, start_page, end_page)
                        section_images = processor.count_images_from_pages(permanent_path, start_page, end_page)
                        
                        if section_text:
                            grading_full_text = section_text
                            expected_images = section_images
                            logger.info(f"Successfully sliced global source. Sliced text len: {len(section_text)}, Sliced images: {section_images}")
                        else:
                            logger.warning(f"Slicing returned empty text. Falling back to full manual.")
                    else:
                        logger.warning(f"Permanent global source file not found at {permanent_path}. Falling back to full manual.")
                
                # --- Topic Knowledge ---
                def preprocess_words(txt: str) -> set:
                    txt_lower = txt.lower()
                    cleaned = re.sub(r'[^a-z0-9\s]', ' ', txt_lower)
                    words = cleaned.split()
                    return {w for w in words if w not in stop_words and len(w) > 1}
                
                student_words = preprocess_words(text)
                grading_words = preprocess_words(grading_full_text)
                
                logger.info(f"Topic Knowledge Debug:")
                logger.info(f"  Student text length: {len(text)}")
                logger.info(f"  Grading text length: {len(grading_full_text)}")
                logger.info(f"  Student words count: {len(student_words)}")
                logger.info(f"  Grading words count: {len(grading_words)}")
                logger.info(f"  Intersection count: {len(student_words.intersection(grading_words))}")
                logger.info(f"  Sample student words: {list(student_words)[:20]}")
                logger.info(f"  Sample grading words: {list(grading_words)[:20]}")
                
                if grading_words:
                    intersection = student_words.intersection(grading_words)
                    containment = len(intersection) / len(grading_words) if len(grading_words) > 0 else 0.0
                    
                    # Strictness setting of 50 corresponds to 0.20 (20%) required coverage
                    strictness_val = request.grading_strictness or 50
                    if strictness_val <= 0:
                        strictness_val = 50
                    coverage_threshold = (strictness_val / 50.0) * 0.20
                    
                    import math
                    topic_similarity = min(math.sqrt(containment / coverage_threshold), 1.0)
                    topic_knowledge_score = 1.0 + topic_similarity * 2.0
                else:
                    topic_knowledge_score = 3.0
                
                # --- Lab Performance ---
                # 1. Output screenshots
                student_images = 0
                try:
                    student_images = processor.count_images(file_path)
                except Exception as e:
                    logger.error(f"Failed to count images for student submission: {e}")
                
                if expected_images > 0:
                    screenshot_score = min(student_images / expected_images, 1.0)
                    screenshot_weight = 0.3
                else:
                    screenshot_score = 1.0
                    screenshot_weight = 0.0
                
                # 2. Code structure
                def get_code_tokens(txt: str) -> set:
                    code_words = set()
                    code_patterns = [
                        r'^\s*(?:def|class|import|from|if|for|while|return|function|var|let|const|public|private)\s',
                        r'[{};]\s*$',
                        r'^\s*\/\/|^\s*#|^\s*\/\*',
                    ]
                    for line in txt.split('\n'):
                        if any(re.match(p, line.strip(), re.IGNORECASE) for p in code_patterns):
                            words = re.findall(r'\b\w+\b', line.lower())
                            code_words.update(words)
                    return code_words

                student_code = get_code_tokens(text)
                grading_code = get_code_tokens(grading_full_text)
                
                if grading_code:
                    code_score = len(student_code.intersection(grading_code)) / len(grading_code)
                    code_weight = 0.4 if expected_images > 0 else 0.5
                else:
                    code_score = 1.0
                    code_weight = 0.0
                
                # 3. Steps attempted
                step_patterns = [r'\bstep\s*\d+\b', r'\btask\s*\d+\b', r'\bquestion\s*\d+\b', r'\bexercise\s*\d+\b']
                grading_steps = set()
                grading_text_lower = grading_full_text.lower()
                for pattern in step_patterns:
                    matches = re.findall(pattern, grading_text_lower)
                    grading_steps.update(matches)
                
                student_text_lower = text.lower()
                if grading_steps:
                    steps_found = sum(1 for step in grading_steps if step in student_text_lower)
                    steps_score = steps_found / len(grading_steps)
                    steps_weight = 1.0 - screenshot_weight - code_weight
                else:
                    steps_score = 1.0
                    steps_weight = 1.0 - screenshot_weight - code_weight if (screenshot_weight + code_weight) < 1.0 else 0.0
                
                total_weight = screenshot_weight + code_weight + steps_weight
                if total_weight > 0:
                    lab_perf_base_ratio = (screenshot_score * screenshot_weight + code_score * code_weight + steps_score * steps_weight) / total_weight
                else:
                    lab_perf_base_ratio = 1.0
                
                lab_performance_base = 1.0 + lab_perf_base_ratio * 2.0
                
                # Apply Plagiarism & AI combined penalty
                combined_plag_ai = max(plag_score, ai_prob)
                if combined_plag_ai < 30:
                    penalty_factor = 1.0
                elif combined_plag_ai < 50:
                    penalty_factor = 0.8
                elif combined_plag_ai < 70:
                    penalty_factor = 0.5
                else:
                    penalty_factor = 0.2
                
                lab_performance_score = lab_performance_base * penalty_factor
                
                # Cap scores between 1.0 and 3.0
                topic_knowledge_score = min(max(topic_knowledge_score, 1.0), 3.0)
                lab_performance_score = min(max(lab_performance_score, 1.0), 3.0)
            else:
                logger.info("No grading global source found for course. Using default scores.")
        else:
            logger.info("No course ID provided. Using default scores.")

        # 6️⃣ Append Report to File
        try:
            from reporter import append_report_to_pdf, append_report_to_docx

            report_lines = [
                "CheqMate Analysis Report",
                "--------------------------------------------------",
                f"Plagiarism Score: {round(plag_score, 2)}%",
                f"AI Probability:   {ai_prob}%",
                f"Topic Knowledge Score: {round(topic_knowledge_score, 2)}",
                f"Lab Performance Score: {round(lab_performance_score, 2)}",
                "",
                "Matches found:"
            ]

            if details:
                for match in details:
                    if match.get("source_type") == "global":
                        report_lines.append(
                            f" - Global Source '{match.get('filename', 'Unknown')}': {round(match['score'], 2)}%"
                        )
                    else:
                        report_lines.append(
                            f" - Submission ID: {match.get('submission_id')} (Similarity: {round(match['score'], 2)}%)"
                        )
            else:
                report_lines.append(" - No significant matches found.")

            report_text = "\n".join(report_lines)

            ext = os.path.splitext(file_path)[1].lower()
            if ext == ".pdf":
                append_report_to_pdf(file_path, report_text)
            elif ext in [".docx", ".doc"]:
                append_report_to_docx(file_path, report_text)

        except Exception as report_err:
            logger.error(f"Failed to append report: {report_err}")

        cleanup()
        return {
            "status": "processed",
            "plagiarism_score": round(plag_score, 2),
            "ai_probability": ai_prob,
            "details": details,
            "peer_comparison_enabled": request.enable_peer_comparison,
            "global_source_checked": request.check_global_source,
            "topic_knowledge_score": round(topic_knowledge_score, 2),
            "lab_performance_score": round(lab_performance_score, 2),
            "message": "Analysis successful"
        }

    except Exception as e:
        cleanup()
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
    
    local_file_path = None
    def cleanup():
        nonlocal local_file_path
        if local_file_path and os.path.exists(local_file_path):
            try:
                os.remove(local_file_path)
                logger.info(f"Cleaned up local temp file: {local_file_path}")
            except Exception as cleanup_err:
                logger.error(f"Failed to cleanup local temp file {local_file_path}: {cleanup_err}")

    if request.file_content:
        try:
            import base64
            content_bytes = base64.b64decode(request.file_content)
            safe_filename = "".join(c for c in request.filename if c.isalnum() or c in "._- ")
            local_file_path = os.path.join(TEMP_DIR, f"global_{request.course_id}_{hashlib.md5(request.filename.encode()).hexdigest()}_{safe_filename}")
            with open(local_file_path, "wb") as f:
                f.write(content_bytes)
            file_path = local_file_path
        except Exception as e:
            logger.error(f"Failed to decode and save global source file content: {e}")
            raise HTTPException(status_code=400, detail=f"Invalid file content: {e}")
    else:
        # Resolve path dynamically
        file_path = resolve_moodledata_path(request.file_path, request.dataroot)

    logger.info(f"Final file_path exists check: {file_path} (exists={os.path.exists(file_path)})")
    if not os.path.exists(file_path):
        cleanup()
        raise HTTPException(status_code=404, detail=f"File not found: {file_path}")
    
    try:
        # Extract text
        text = processor.extract_text(file_path)
        if not text:
            logger.warning("No text extracted from global source. Proceeding with empty text.")
            text = ""
        
        # Generate shingles
        shingles = detector.get_shingles(text)
        
        # Generate content hash for deduplication
        content_hash = hashlib.md5(text.encode()).hexdigest()
        
        # Count images in the uploaded document
        image_count = 0
        try:
            image_count = processor.count_images(file_path)
        except Exception as e:
            logger.error(f"Failed to count images for global source: {e}")

        # Auto-extract sections if PDF
        sections_list = []
        if file_path.lower().endswith('.pdf'):
            sections_list = processor.auto_extract_sections(file_path)
        
        sections_json = json.dumps(sections_list) if sections_list else None

        # Save to database
        saved = storage.save_global_source(
            request.course_id,
            request.filename,
            content_hash,
            shingles,
            full_text=text,
            image_count=image_count,
            sections=sections_json
        )
        
        if saved:
            try:
                permanent_filename = f"{request.course_id}_{request.filename}"
                permanent_path = os.path.join(GLOBAL_SOURCES_DIR, permanent_filename)
                shutil.copy2(file_path, permanent_path)
                logger.info(f"Saved global source permanently to {permanent_path}")
            except Exception as copy_err:
                logger.error(f"Failed to copy global source file to permanent storage: {copy_err}")

        cleanup()
        if saved:
            return {
                "status": "success",
                "message": f"Global source '{request.filename}' uploaded successfully",
                "sections": sections_list
            }
        else:
            return {
                "status": "exists",
                "message": f"Global source '{request.filename}' already exists"
            }
            
    except Exception as e:
        cleanup()
        logger.error(f"Failed to upload global source: {e}")
        if isinstance(e, HTTPException):
            raise e
        raise HTTPException(status_code=500, detail=str(e))


@app.post("/global-source/update-sections")
async def update_global_source_sections(request: UpdateSectionsRequest):
    """Save/update the manual section ranges for a global source"""
    try:
        # Validate sections JSON syntax
        try:
            json.loads(request.sections)
        except Exception:
            raise HTTPException(status_code=400, detail="Invalid JSON for sections")
            
        storage.update_global_source_sections(request.course_id, request.filename, request.sections)
        return {"status": "success", "message": f"Global source sections updated successfully."}
    except Exception as e:
        logger.error(f"Failed to update global source sections: {e}")
        if isinstance(e, HTTPException):
            raise e
        raise HTTPException(status_code=500, detail=str(e))


@app.post("/global-source/set-grading")
async def set_grading_global_source(request: SetGradingRequest):
    """Set a specific global source as the grading source for a course"""
    try:
        storage.set_grading_global_source(request.course_id, request.filename)
        return {"status": "success", "message": f"Global source '{request.filename}' set as grading doc."}
    except Exception as e:
        logger.error(f"Failed to set grading global source: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@app.get("/global-source/download/{course_id}/{filename}")
def download_global_source_file(course_id: int, filename: str):
    """Download a permanently stored global source manual"""
    permanent_filename = f"{course_id}_{filename}"
    permanent_path = os.path.join(GLOBAL_SOURCES_DIR, permanent_filename)
    if not os.path.exists(permanent_path):
        raise HTTPException(status_code=404, detail="Global source file not found")
    return FileResponse(path=permanent_path, filename=filename, media_type="application/pdf")


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


from fastapi import Request
import tempfile
import os
import difflib
import fitz

@app.post("/advanced_report")
async def advanced_report(request: Request):
    """
    Generates a PDF report highlighting copied text between source and multiple peers.
    Adds a summary cover page with a color legend and Real Names.
    """
    try:
        form = await request.form()
        
        source_file = form.get("source_file")
        if not source_file:
            raise HTTPException(status_code=400, detail="Missing source_file")
            
        submission_id = form.get("submission_id", "Unknown")
        plagiarism_score = float(form.get("plagiarism_score", 0.0))
        ai_probability = float(form.get("ai_probability", 0.0))
        
        peer_files = []
        peer_names = []
        peer_scores = []
        
        # Parse Moodle PHP array payload (e.g. peer_files[0], peer_names[0])
        for key in form.keys():
            if key.startswith("peer_files["):
                peer_files.append(form[key])
            elif key.startswith("peer_names["):
                peer_names.append(form[key])
            elif key.startswith("peer_scores["):
                peer_scores.append(float(form[key]))

        # Save source file
        with tempfile.NamedTemporaryFile(delete=False, suffix=".pdf") as sf:
            sf.write(await source_file.read())
            source_path = sf.name

        source_text = processor.extract_text(source_path)
        
        doc = fitz.open(source_path)
        
        # --- Add Summary Cover Page ---
        page0 = doc.new_page(pno=0)
        page0.insert_text((50, 50), "CheqMate Advanced Plagiarism Report", fontsize=18, fontname="helv")
        page0.insert_text((50, 80), f"Plagiarism Score: {plagiarism_score}%", fontsize=14, fontname="helv")
        page0.insert_text((50, 100), f"AI Probability: {ai_probability}%", fontsize=14, fontname="helv")
        
        y_offset = 140
        page0.insert_text((50, y_offset), "Matches Highlight Legend:", fontsize=14, fontname="helv")
        y_offset += 30
        
        colors = [(1, 1, 0), (0, 1, 1), (0, 1, 0), (1, 0.5, 0), (1, 0, 1)] # Yellow, Cyan, Green, Orange, Magenta
        
        # --- Process Each Peer ---
        for idx, pf in enumerate(peer_files):
            peer_name = peer_names[idx] if idx < len(peer_names) else f"Peer {idx+1}"
            peer_score = peer_scores[idx] if idx < len(peer_scores) else 0.0
            color = colors[idx % len(colors)]
            
            # Draw color box legend
            rect = fitz.Rect(50, y_offset-12, 65, y_offset+3)
            page0.draw_rect(rect, color=color, fill=color)
            page0.insert_text((75, y_offset), f"{peer_name} - {peer_score}%", fontsize=12, fontname="helv")
            y_offset += 25
            
            with tempfile.NamedTemporaryFile(delete=False, suffix=".pdf") as pftmp:
                pftmp.write(await pf.read())
                peer_path = pftmp.name
                
            peer_text = processor.extract_text(peer_path)
            s = difflib.SequenceMatcher(None, source_text.split(), peer_text.split())
            blocks = s.get_matching_blocks()
            
            for block in blocks:
                if block.size > 5:
                    snippet_words = source_text.split()[block.a:block.a + block.size]
                    snippet_str = " ".join(snippet_words)
                    
                    for page_num in range(1, len(doc)): # skip page 0
                        page = doc[page_num]
                        text_instances = page.search_for(snippet_str)
                        if not text_instances:
                            # Fallback chunk highlighting
                            for i in range(0, len(snippet_words), 3):
                                chunk = " ".join(snippet_words[i:i+3])
                                if len(chunk) > 10:
                                    instances = page.search_for(chunk)
                                    for inst in instances:
                                        highlight = page.add_highlight_annot(inst)
                                        highlight.set_colors(stroke=color)
                                        highlight.update()
                        else:
                            for inst in text_instances:
                                highlight = page.add_highlight_annot(inst)
                                highlight.set_colors(stroke=color)
                                highlight.update()
                                
            os.unlink(peer_path)
                            
        # Save highlighted PDF
        highlighted_path = source_path + "_highlighted.pdf"
        doc.save(highlighted_path)
        doc.close()
        
        os.unlink(source_path)
        
        return FileResponse(path=highlighted_path, media_type="application/pdf")

    except Exception as e:
        logger.error(f"Advanced Report Gen Failed: {e}")
        raise HTTPException(status_code=500, detail=str(e))

if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8000)

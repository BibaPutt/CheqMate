import sqlite3
import json
import os
import logging
import threading

DB_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "cheqmate.db")

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("Storage")

class Storage:
    _local = threading.local()
    
    def __init__(self):
        self.create_tables()
    
    def _get_conn(self):
        """Get thread-local connection for thread safety"""
        if not hasattr(self._local, 'conn') or self._local.conn is None:
            self._local.conn = sqlite3.connect(DB_PATH, check_same_thread=False)
            self._local.conn.execute("PRAGMA journal_mode=WAL")  # Better durability
            self._local.conn.execute("PRAGMA synchronous=FULL")  # Force disk writes
        return self._local.conn

    def create_tables(self):
        conn = self._get_conn()
        cursor = conn.cursor()
        
        # Fingerprints table with assignment_id for proper scoping
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS fingerprints (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                submission_id INTEGER UNIQUE,
                context_id INTEGER,
                assignment_id INTEGER,
                hashes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ''')
        
        # Global sources table for teacher reference documents
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS global_sources (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                course_id INTEGER,
                filename TEXT,
                content_hash TEXT,
                hashes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ''')
        
        # Check if assignment_id column exists, add if not (migration for existing DBs)
        cursor.execute("PRAGMA table_info(fingerprints)")
        columns = [col[1] for col in cursor.fetchall()]
        if 'assignment_id' not in columns:
            cursor.execute("ALTER TABLE fingerprints ADD COLUMN assignment_id INTEGER")
            logger.info("Migrated fingerprints table: added assignment_id column")
        
        # Add indexes AFTER migration (so column exists)
        try:
            cursor.execute('CREATE INDEX IF NOT EXISTS idx_fingerprints_assignment ON fingerprints(assignment_id)')
            cursor.execute('CREATE INDEX IF NOT EXISTS idx_fingerprints_context ON fingerprints(context_id)')
            cursor.execute('CREATE INDEX IF NOT EXISTS idx_global_sources_course ON global_sources(course_id)')
        except Exception as e:
            logger.warning(f"Index creation warning (may already exist): {e}")
        
        conn.commit()
        logger.info(f"Database initialized at {DB_PATH}")

    def save_fingerprint(self, submission_id, context_id, hashes, assignment_id=None):
        """Save document fingerprint with explicit commit for persistence"""
        conn = self._get_conn()
        cursor = conn.cursor()
        try:
            # Remove existing if any (re-submission)
            cursor.execute("DELETE FROM fingerprints WHERE submission_id = ?", (submission_id,))
            cursor.execute(
                "INSERT INTO fingerprints (submission_id, context_id, assignment_id, hashes) VALUES (?, ?, ?, ?)",
                (submission_id, context_id, assignment_id, json.dumps(list(hashes)))
            )
            conn.commit()  # Explicit commit for durability
            logger.info(f"Saved fingerprint for submission {submission_id}, assignment {assignment_id}")
        except Exception as e:
            logger.error(f"Error saving fingerprint: {e}")
            conn.rollback()
            raise

    def get_all_fingerprints(self, exclude_submission_id, context_id=None, assignment_id=None):
        """
        Get all fingerprints for comparison.
        If assignment_id is provided, only get fingerprints from that assignment (peer-to-peer).
        Otherwise fallback to context_id for backward compatibility.
        """
        conn = self._get_conn()
        cursor = conn.cursor()
        
        if assignment_id:
            # Peer comparison within same assignment only
            cursor.execute(
                "SELECT submission_id, hashes FROM fingerprints WHERE assignment_id = ? AND submission_id != ?", 
                (assignment_id, exclude_submission_id)
            )
        else:
            # Fallback to context for backward compatibility
            cursor.execute(
                "SELECT submission_id, hashes FROM fingerprints WHERE context_id = ? AND submission_id != ?", 
                (context_id, exclude_submission_id)
            )
        
        rows = cursor.fetchall()
        result = []
        for r in rows:
            try:
                hashes = set(json.loads(r[1]))
                result.append({"submission_id": r[0], "hashes": hashes})
            except json.JSONDecodeError:
                logger.warning(f"Invalid JSON for submission {r[0]}")
        
        logger.info(f"Found {len(result)} peer submissions for comparison")
        return result

    def save_global_source(self, course_id, filename, content_hash, hashes):
        """Save global source document fingerprint"""
        conn = self._get_conn()
        cursor = conn.cursor()
        try:
            # Check if already exists (same content hash)
            cursor.execute(
                "SELECT id FROM global_sources WHERE course_id = ? AND content_hash = ?",
                (course_id, content_hash)
            )
            if cursor.fetchone():
                logger.info(f"Global source already exists: {filename}")
                return False
            
            cursor.execute(
                "INSERT INTO global_sources (course_id, filename, content_hash, hashes) VALUES (?, ?, ?, ?)",
                (course_id, filename, content_hash, json.dumps(list(hashes)))
            )
            conn.commit()
            logger.info(f"Saved global source: {filename} for course {course_id}")
            return True
        except Exception as e:
            logger.error(f"Error saving global source: {e}")
            conn.rollback()
            raise

    def get_global_sources(self, course_id):
        """Get all global source fingerprints for a course"""
        conn = self._get_conn()
        cursor = conn.cursor()
        cursor.execute(
            "SELECT filename, hashes FROM global_sources WHERE course_id = ?",
            (course_id,)
        )
        rows = cursor.fetchall()
        result = []
        for r in rows:
            try:
                hashes = set(json.loads(r[1]))
                result.append({"filename": r[0], "hashes": hashes, "source_type": "global"})
            except json.JSONDecodeError:
                logger.warning(f"Invalid JSON for global source {r[0]}")
        
        logger.info(f"Found {len(result)} global sources for course {course_id}")
        return result

    def delete_global_source(self, course_id, filename=None):
        """Delete global source(s) for a course"""
        conn = self._get_conn()
        cursor = conn.cursor()
        try:
            if filename:
                cursor.execute(
                    "DELETE FROM global_sources WHERE course_id = ? AND filename = ?",
                    (course_id, filename)
                )
            else:
                cursor.execute("DELETE FROM global_sources WHERE course_id = ?", (course_id,))
            conn.commit()
            logger.info(f"Deleted global sources for course {course_id}")
            return cursor.rowcount
        except Exception as e:
            logger.error(f"Error deleting global source: {e}")
            conn.rollback()
            raise

    def clear_assignment_cache(self, assignment_id):
        """Clear all fingerprints for a specific assignment (cache clearing feature)"""
        conn = self._get_conn()
        cursor = conn.cursor()
        try:
            cursor.execute("DELETE FROM fingerprints WHERE assignment_id = ?", (assignment_id,))
            deleted_count = cursor.rowcount
            conn.commit()
            logger.info(f"Cleared {deleted_count} fingerprints for assignment {assignment_id}")
            return deleted_count
        except Exception as e:
            logger.error(f"Error clearing cache: {e}")
            conn.rollback()
            raise

    def get_fingerprint_count(self, assignment_id=None):
        """Get count of stored fingerprints, optionally filtered by assignment"""
        conn = self._get_conn()
        cursor = conn.cursor()
        if assignment_id:
            cursor.execute("SELECT COUNT(*) FROM fingerprints WHERE assignment_id = ?", (assignment_id,))
        else:
            cursor.execute("SELECT COUNT(*) FROM fingerprints")
        return cursor.fetchone()[0]

    def close(self):
        """Explicitly close the connection"""
        if hasattr(self._local, 'conn') and self._local.conn:
            self._local.conn.close()
            self._local.conn = None

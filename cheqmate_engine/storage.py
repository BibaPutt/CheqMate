import sqlite3
import json
import os

DB_PATH = "cheqmate.db"

class Storage:
    def __init__(self):
        self.conn = sqlite3.connect(DB_PATH, check_same_thread=False)
        self.create_tables()

    def create_tables(self):
        cursor = self.conn.cursor()
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS fingerprints (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                submission_id INTEGER UNIQUE,
                context_id INTEGER,
                hashes TEXT
            )
        ''')
        self.conn.commit()

    def save_fingerprint(self, submission_id, context_id, hashes):
        cursor = self.conn.cursor()
        # Remove existing if any (re-submission)
        cursor.execute("DELETE FROM fingerprints WHERE submission_id = ?", (submission_id,))
        cursor.execute("INSERT INTO fingerprints (submission_id, context_id, hashes) VALUES (?, ?, ?)",
                       (submission_id, context_id, json.dumps(hashes)))
        self.conn.commit()

    def get_all_fingerprints(self, exclude_submission_id, context_id):
        """
        Get all fingerprints for the same context (assignment/course), 
        excluding the current submission.
        """
        cursor = self.conn.cursor()
        cursor.execute("SELECT submission_id, hashes FROM fingerprints WHERE context_id = ? AND submission_id != ?", 
                       (context_id, exclude_submission_id))
        rows = cursor.fetchall()
        return [{"submission_id": r[0], "hashes": set(json.loads(r[1]))} for r in rows]

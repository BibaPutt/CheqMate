import re

class PlagiarismDetector:
    def __init__(self):
        self.k_gram_len = 5

    def preprocess(self, text):
        # Lowercase, remove non-alphanumeric, collapse whitespace
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
            # Hash the tuple for storage efficiency
            shingles.add(hash(shingle))
        return shingles

    def calculate_similarity(self, shingles_a, shingles_b):
        if not shingles_a or not shingles_b:
            return 0.0
        
        intersection = len(shingles_a.intersection(shingles_b))
        union = len(shingles_a.union(shingles_b))
        
        return (intersection / union) * 100 if union > 0 else 0.0

    def check_plagiarism(self, current_shingles, previous_submissions):
        """
        Compare current submission against a list of previous submissions.
        Returns the max similarity found.
        """
        max_score = 0.0
        details = []

        for sub in previous_submissions:
            score = self.calculate_similarity(current_shingles, sub['hashes'])
            if score > 0:
                details.append({
                    "submission_id": sub['submission_id'],
                    "score": score
                })
            if score > max_score:
                max_score = score

        return max_score, details

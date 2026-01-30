import re
import hashlib
import logging
from typing import Set, List, Dict, Tuple, Optional

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("Detector")

class PlagiarismDetector:
    def __init__(self):
        self.k_gram_len = 5  # Keep at 5 for balanced sensitivity
        # Section patterns to identify and exclude (can be extended via skip_patterns)
        self.default_section_markers = [
            r'^#+\s*aim',
            r'^#+\s*introduction',
            r'^#+\s*objective',
            r'^#+\s*abstract',
            r'^aim\s*:',
            r'^introduction\s*:',
            r'^objective\s*:',
        ]
        # Code detection patterns
        self.code_patterns = [
            r'^```[\s\S]*?```',  # Markdown code blocks
            r'^\s*(?:def|class|import|from|if|for|while|return|function|var|let|const|public|private)\s',
            r'[{};]\s*$',  # Lines ending with code-like characters
            r'^\s*\/\/|^\s*#|^\s*\/\*',  # Comment patterns
        ]

    def preprocess(self, text: str, skip_patterns: Optional[List[str]] = None) -> str:
        """
        Preprocess text: lowercase, remove non-alphanumeric, collapse whitespace.
        Also remove sections matching skip patterns.
        """
        # First, remove code blocks if present
        text = self._remove_code_blocks(text)
        
        # Remove sections matching skip patterns
        if skip_patterns:
            text = self._remove_skipped_sections(text, skip_patterns)
        
        # Standard preprocessing
        text = text.lower()
        text = re.sub(r'[^a-z0-9\s]', '', text)
        text = re.sub(r'\s+', ' ', text).strip()
        return text

    def _remove_code_blocks(self, text: str) -> str:
        """Remove code blocks from text (they shouldn't count as plagiarism)"""
        # Remove markdown code blocks
        text = re.sub(r'```[\s\S]*?```', ' ', text)
        text = re.sub(r'`[^`]+`', ' ', text)
        
        # Identify and remove lines that look like code
        lines = text.split('\n')
        filtered_lines = []
        in_code_block = False
        
        for line in lines:
            # Detect code block start/end
            stripped = line.strip()
            
            # Check if line looks like code
            is_code_line = any(re.match(pattern, stripped, re.IGNORECASE) 
                             for pattern in self.code_patterns)
            
            # Check for indented code (4+ spaces or tab)
            if re.match(r'^(\s{4,}|\t)', line) and len(stripped) > 0:
                is_code_line = True
            
            if not is_code_line:
                filtered_lines.append(line)
        
        return '\n'.join(filtered_lines)

    def _remove_skipped_sections(self, text: str, skip_patterns: List[str]) -> str:
        """
        Remove sections that match skip patterns (like 'aim', 'introduction').
        Detects section headers and removes content until next section.
        """
        lines = text.split('\n')
        filtered_lines = []
        skip_until_next_section = False
        
        # Build regex patterns from skip_patterns
        section_patterns = []
        for pattern in skip_patterns:
            pattern = pattern.strip().lower()
            if pattern:
                # Create pattern to match section headers
                section_patterns.append(rf'^#+\s*{re.escape(pattern)}')
                section_patterns.append(rf'^{re.escape(pattern)}\s*:')
                section_patterns.append(rf'^{re.escape(pattern)}\s*$')
        
        # Also include default markers
        section_patterns.extend(self.default_section_markers)
        
        # Pattern to detect any section header (to know when skip ends)
        any_header_pattern = r'^#+\s+\w|^\w+\s*:'
        
        for line in lines:
            stripped = line.strip().lower()
            
            # Check if this line starts a section we should skip
            is_skip_section = any(re.match(p, stripped, re.IGNORECASE) 
                                 for p in section_patterns)
            
            if is_skip_section:
                skip_until_next_section = True
                continue
            
            # Check if this is a new section (end of skip)
            if skip_until_next_section and re.match(any_header_pattern, stripped):
                # Check it's not another skip section
                if not any(re.match(p, stripped, re.IGNORECASE) for p in section_patterns):
                    skip_until_next_section = False
            
            if not skip_until_next_section:
                filtered_lines.append(line)
        
        return '\n'.join(filtered_lines)

    def get_shingles(self, text: str, skip_patterns: Optional[List[str]] = None) -> Set[int]:
        """Generate k-gram shingles from text"""
        words = self.preprocess(text, skip_patterns).split()
        if len(words) < self.k_gram_len:
            return set()
        
        shingles = set()
        for i in range(len(words) - self.k_gram_len + 1):
            shingle = tuple(words[i : i + self.k_gram_len])
            # Use consistent hash for cross-session stability
            shingle_str = ' '.join(shingle)
            hash_val = int(hashlib.md5(shingle_str.encode()).hexdigest()[:16], 16)
            shingles.add(hash_val)
        
        return shingles

    def calculate_similarity(self, shingles_a: Set[int], shingles_b: Set[int]) -> float:
        """Calculate Jaccard similarity between two shingle sets"""
        if not shingles_a or not shingles_b:
            return 0.0
        
        intersection = len(shingles_a.intersection(shingles_b))
        union = len(shingles_a.union(shingles_b))
        
        return (intersection / union) * 100 if union > 0 else 0.0

    def calculate_weighted_similarity(self, shingles_a: Set[int], shingles_b: Set[int]) -> float:
        """
        Calculate similarity with weighting for size difference.
        Prevents small documents from getting unfairly high scores.
        """
        if not shingles_a or not shingles_b:
            return 0.0
        
        intersection = len(shingles_a.intersection(shingles_b))
        
        # Use minimum size as denominator for containment metric
        # This detects when one doc is subset of another
        min_size = min(len(shingles_a), len(shingles_b))
        containment = (intersection / min_size) * 100 if min_size > 0 else 0.0
        
        # Also calculate Jaccard
        union = len(shingles_a.union(shingles_b))
        jaccard = (intersection / union) * 100 if union > 0 else 0.0
        
        # Combined score: weight toward containment for better plagiarism detection
        # but temper with Jaccard to avoid false positives on tiny matches
        combined = (containment * 0.7) + (jaccard * 0.3)
        
        return min(combined, 100.0)

    def check_plagiarism(
        self, 
        current_shingles: Set[int], 
        previous_submissions: List[Dict],
        global_sources: Optional[List[Dict]] = None,
        threshold: float = 5.0
    ) -> Tuple[float, List[Dict]]:
        """
        Compare current submission against previous submissions and global sources.
        Returns the max similarity found and details of all significant matches.
        
        Args:
            current_shingles: Shingle set of current document
            previous_submissions: List of peer submissions with 'submission_id' and 'hashes'
            global_sources: List of global source docs with 'filename' and 'hashes'
            threshold: Minimum similarity % to report as a match
        """
        max_score = 0.0
        details = []

        # Check against peer submissions
        for sub in previous_submissions:
            score = self.calculate_weighted_similarity(current_shingles, sub['hashes'])
            if score > threshold:
                details.append({
                    "submission_id": sub['submission_id'],
                    "score": score,
                    "source_type": "peer"
                })
            if score > max_score:
                max_score = score

        # Check against global sources
        if global_sources:
            for source in global_sources:
                score = self.calculate_weighted_similarity(current_shingles, source['hashes'])
                if score > threshold:
                    details.append({
                        "filename": source.get('filename', 'Unknown'),
                        "score": score,
                        "source_type": "global"
                    })
                if score > max_score:
                    max_score = score

        # Sort by score descending
        details.sort(key=lambda x: x['score'], reverse=True)
        
        logger.info(f"Plagiarism check: max_score={max_score:.2f}%, {len(details)} matches found")
        return max_score, details


class MinHashLSH:
    """
    MinHash Locality Sensitive Hashing for scalable similarity detection.
    Used when there are many submissions to compare against.
    """
    def __init__(self, num_hashes: int = 100, num_bands: int = 20):
        self.num_hashes = num_hashes
        self.num_bands = num_bands
        self.rows_per_band = num_hashes // num_bands
        # Generate random hash functions (using prime coefficients)
        self.a_coeffs = [hash(f"a_{i}") % (2**31 - 1) for i in range(num_hashes)]
        self.b_coeffs = [hash(f"b_{i}") % (2**31 - 1) for i in range(num_hashes)]
        self.prime = 2**31 - 1
    
    def create_signature(self, shingles: Set[int]) -> List[int]:
        """Create MinHash signature for a shingle set"""
        if not shingles:
            return [self.prime] * self.num_hashes
        
        signature = []
        for i in range(self.num_hashes):
            min_hash = self.prime
            for shingle in shingles:
                hash_val = (self.a_coeffs[i] * shingle + self.b_coeffs[i]) % self.prime
                min_hash = min(min_hash, hash_val)
            signature.append(min_hash)
        
        return signature
    
    def estimate_similarity(self, sig1: List[int], sig2: List[int]) -> float:
        """Estimate Jaccard similarity from MinHash signatures"""
        if len(sig1) != len(sig2):
            return 0.0
        
        matches = sum(1 for a, b in zip(sig1, sig2) if a == b)
        return (matches / len(sig1)) * 100
    
    def get_candidate_pairs(self, signatures: Dict[int, List[int]]) -> Set[Tuple[int, int]]:
        """Find candidate pairs using LSH banding technique"""
        buckets = {}  # band_idx -> hash -> list of submission_ids
        candidate_pairs = set()
        
        for sub_id, sig in signatures.items():
            for band_idx in range(self.num_bands):
                start = band_idx * self.rows_per_band
                end = start + self.rows_per_band
                band = tuple(sig[start:end])
                band_hash = hash(band)
                
                key = (band_idx, band_hash)
                if key not in buckets:
                    buckets[key] = []
                
                # Add pairs with existing items in same bucket
                for other_id in buckets[key]:
                    candidate_pairs.add((min(sub_id, other_id), max(sub_id, other_id)))
                
                buckets[key].append(sub_id)
        
        return candidate_pairs

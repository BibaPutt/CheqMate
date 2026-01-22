import re
import math
from collections import Counter

class AIDetector:
    def __init__(self):
        # We need a reference corpus language model for a 'real' perplexity check.
        # But fully offline without big weights, we can only approximate or use simple entropy.
        # For this MVP, we will use 'Text Entropy' and 'Burstiness' as proxies.
        # Note: This is a HEURISTIC approach and not 100% accurate.
        pass

    def calculate_entropy(self, text):
        """
        Calculates Shannon entropy of character distribution.
        AI text often has slightly more predictable distribution than chaotic human error-filled text.
        (Actually, AI tries to maximize entropy in sampling, but lexical diversity might be lower).
        """
        if not text: return 0
        prob = [ float(text.count(c)) / len(text) for c in dict.fromkeys(list(text)) ]
        entropy = - sum([ p * math.log(p) / math.log(2.0) for p in prob ])
        return entropy

    def calculate_burstiness(self, text):
        """
        Sentence length variation (Standard Deviation / Mean).
        AI text is often very uniform in sentence length.
        Humans vary sentence length more (short. Long complex explanation. Short.)
        """
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
            return 0  # Too short

        burstiness = self.calculate_burstiness(text)
        # entropy = self.calculate_entropy(text)

        # Heuristic Thresholds (tuned on observations)
        # Low burstiness (< 0.4) indicates uniformity (AI-like)
        # High burstiness (> 0.6) indicates human-like
        
        # We invert burstiness to get "AI Probability" conceptually
        # If burstiness is 0, ai_prob is 100. If burstiness is 1, ai_prob is 0.
        
        ai_prob = max(0, min(100, (1.0 - burstiness) * 100))
        
        # Adjust curve - usually values are between 0.3 and 0.7
        # 0.3 burstiness -> 70% AI (Suspicious)
        # 0.5 burstiness -> 50% AI (Neutral)
        # 0.7 burstiness -> 30% AI (Human likely)
        
        return round(ai_prob, 2)

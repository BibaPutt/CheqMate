import re
import math
import logging
from collections import Counter
from typing import Dict, List, Optional

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("AIDetector")

class AIDetector:
    """
    Enhanced AI content detection using multiple statistical features.
    Note: This is a heuristic approach using NLP/statistical features commonly
    used in machine learning based AI detectors.
    """
    
    def __init__(self):
        # Thresholds calibrated on observed AI vs human text patterns
        self.min_text_length = 100  # Minimum chars for reliable detection
        
        # Common AI sentence starters (AI tends to repeat these patterns)
        self.ai_sentence_starters = [
            'in conclusion',
            'furthermore',
            'additionally',
            'moreover',
            'in addition',
            'it is important to',
            'it should be noted',
            'this suggests that',
            'studies have shown',
            'research indicates',
            'as a result',
            'consequently',
            'therefore',
            'thus',
            'hence',
            'in summary',
            'to summarize',
            'in essence',
            'overall',
        ]
        
        # Words that AI overuses
        self.ai_overused_words = [
            'comprehensive', 'crucial', 'essential', 'significant',
            'fundamental', 'substantial', 'considerable', 'notable',
            'paramount', 'pivotal', 'imperative', 'intricate',
            'multifaceted', 'nuanced', 'holistic', 'synergy',
            'leverage', 'optimize', 'streamline', 'facilitate',
        ]

    def calculate_burstiness(self, text: str) -> float:
        """
        Sentence length variation (Standard Deviation / Mean).
        AI text is often very uniform in sentence length.
        Humans vary sentence length more (short. Long complex explanation. Short.)
        
        Lower burstiness = more AI-like
        Higher burstiness = more human-like
        """
        sentences = re.split(r'[.!?]+', text)
        sentences = [s.strip() for s in sentences if len(s.strip()) > 5]
        if len(sentences) < 3:
            return 0.5  # Neutral if too few sentences

        lengths = [len(s.split()) for s in sentences]
        mean_len = sum(lengths) / len(lengths)
        if mean_len == 0: 
            return 0.5
        
        variance = sum([(l - mean_len)**2 for l in lengths]) / len(lengths)
        std_dev = math.sqrt(variance)
        
        return std_dev / mean_len

    def calculate_vocabulary_diversity(self, text: str) -> float:
        """
        Type-Token Ratio (TTR) - ratio of unique words to total words.
        AI often has lower diversity as it repeats common words.
        
        Higher TTR = more diverse = more human-like
        Lower TTR = less diverse = more AI-like
        """
        words = re.findall(r'\b[a-z]+\b', text.lower())
        if len(words) < 10:
            return 0.5
        
        unique_words = set(words)
        ttr = len(unique_words) / len(words)
        
        # Normalize to roughly 0-1 range (typical TTR is 0.2-0.6)
        return min(ttr * 2, 1.0)

    def calculate_sentence_starter_repetition(self, text: str) -> float:
        """
        Measure how often sentences start with the same patterns.
        AI tends to start sentences with common transitions more uniformly.
        
        Returns: ratio of repeated starters (higher = more AI-like)
        """
        sentences = re.split(r'[.!?]+', text)
        sentences = [s.strip().lower() for s in sentences if len(s.strip()) > 10]
        
        if len(sentences) < 5:
            return 0.3
        
        # Get first 3 words of each sentence
        starters = []
        for s in sentences:
            words = s.split()[:3]
            starters.append(' '.join(words))
        
        # Count repetitions
        starter_counts = Counter(starters)
        repeated = sum(c - 1 for c in starter_counts.values() if c > 1)
        
        repetition_ratio = repeated / len(sentences)
        return min(repetition_ratio, 1.0)

    def calculate_ai_word_usage(self, text: str) -> float:
        """
        Measure frequency of words commonly overused by AI.
        
        Returns: ratio of AI-typical words (higher = more AI-like)
        """
        words = re.findall(r'\b[a-z]+\b', text.lower())
        if len(words) < 50:
            return 0.2
        
        ai_word_count = sum(1 for w in words if w in self.ai_overused_words)
        
        # Normalize (typically 0-3% is human, above is suspicious)
        ratio = ai_word_count / len(words)
        return min(ratio * 20, 1.0)  # Scale up for visibility

    def calculate_sentence_starter_ai_patterns(self, text: str) -> float:
        """
        Check for AI-typical sentence starters.
        
        Returns: ratio of AI-pattern starters (higher = more AI-like)
        """
        text_lower = text.lower()
        sentences = re.split(r'[.!?]+', text_lower)
        sentences = [s.strip() for s in sentences if len(s.strip()) > 10]
        
        if len(sentences) < 3:
            return 0.2
        
        ai_pattern_count = 0
        for sentence in sentences:
            for starter in self.ai_sentence_starters:
                if sentence.startswith(starter):
                    ai_pattern_count += 1
                    break
        
        ratio = ai_pattern_count / len(sentences)
        return ratio

    def calculate_average_word_length(self, text: str) -> float:
        """
        AI tends to use medium-length words consistently.
        Humans use more varied word lengths (mixing short and long).
        
        Returns: how uniform word lengths are (higher = more AI-like)
        """
        words = re.findall(r'\b[a-z]+\b', text.lower())
        if len(words) < 20:
            return 0.3
        
        lengths = [len(w) for w in words]
        mean_len = sum(lengths) / len(lengths)
        variance = sum([(l - mean_len)**2 for l in lengths]) / len(lengths)
        std_dev = math.sqrt(variance)
        
        # Lower std_dev = more uniform = more AI-like
        # Typical human std_dev is 2.5-3.5, AI is often 2.0-2.5
        uniformity = max(0, 1 - (std_dev / 4))
        return uniformity

    def detect(self, text: str) -> float:
        """
        Detect AI-generated content probability.
        
        Returns: AI probability percentage (0-100)
        """
        if len(text) < self.min_text_length:
            logger.info(f"Text too short ({len(text)} chars) for reliable AI detection")
            return 0.0

        # Calculate all features
        burstiness = self.calculate_burstiness(text)
        vocab_diversity = self.calculate_vocabulary_diversity(text)
        starter_repetition = self.calculate_sentence_starter_repetition(text)
        ai_word_usage = self.calculate_ai_word_usage(text)
        ai_starters = self.calculate_sentence_starter_ai_patterns(text)
        word_uniformity = self.calculate_average_word_length(text)
        
        # Log individual scores for debugging
        logger.debug(f"Burstiness: {burstiness:.3f}")
        logger.debug(f"Vocab Diversity: {vocab_diversity:.3f}")
        logger.debug(f"Starter Repetition: {starter_repetition:.3f}")
        logger.debug(f"AI Word Usage: {ai_word_usage:.3f}")
        logger.debug(f"AI Starters: {ai_starters:.3f}")
        logger.debug(f"Word Uniformity: {word_uniformity:.3f}")

        # Weighted combination of features
        # Each feature contributes to AI probability
        ai_signals = [
            (1.0 - burstiness) * 0.20,        # Low burstiness = AI
            (1.0 - vocab_diversity) * 0.15,   # Low diversity = AI
            starter_repetition * 0.15,         # High repetition = AI
            ai_word_usage * 0.20,             # High AI words = AI
            ai_starters * 0.15,               # High AI starters = AI
            word_uniformity * 0.15,           # High uniformity = AI
        ]
        
        # Sum weighted signals
        ai_prob = sum(ai_signals) * 100
        
        # Clamp to 0-100 range
        ai_prob = max(0, min(100, ai_prob))
        
        logger.info(f"AI Detection: {ai_prob:.2f}% probability")
        
        return round(ai_prob, 2)

    def get_detailed_analysis(self, text: str) -> Dict:
        """
        Get detailed breakdown of AI detection metrics.
        Useful for displaying to users.
        """
        if len(text) < self.min_text_length:
            return {
                "ai_probability": 0,
                "error": "Text too short for analysis",
                "metrics": {}
            }
        
        metrics = {
            "burstiness": {
                "value": round(self.calculate_burstiness(text), 3),
                "interpretation": "Lower values suggest AI (uniform sentence lengths)"
            },
            "vocabulary_diversity": {
                "value": round(self.calculate_vocabulary_diversity(text), 3),
                "interpretation": "Lower values suggest AI (repetitive vocabulary)"
            },
            "sentence_repetition": {
                "value": round(self.calculate_sentence_starter_repetition(text), 3),
                "interpretation": "Higher values suggest AI (repeated sentence patterns)"
            },
            "ai_word_frequency": {
                "value": round(self.calculate_ai_word_usage(text), 3),
                "interpretation": "Higher values suggest AI (overuse of formal words)"
            },
            "transition_patterns": {
                "value": round(self.calculate_sentence_starter_ai_patterns(text), 3),
                "interpretation": "Higher values suggest AI (formulaic transitions)"
            },
            "word_length_uniformity": {
                "value": round(self.calculate_average_word_length(text), 3),
                "interpretation": "Higher values suggest AI (uniform word lengths)"
            }
        }
        
        return {
            "ai_probability": self.detect(text),
            "metrics": metrics
        }

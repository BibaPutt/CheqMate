import os
import joblib
import logging
import re
import math
from collections import Counter
from typing import Dict

# Logging setup
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("AIDetector")


class AIDetector:
    """
    AI Detector using a Hybrid Approach:
    - 70% Heuristics (Burstiness, Entropy, Lexical Diversity)
    - 30% Machine Learning Model (TF-IDF + Logistic Regression)
    """

    def __init__(self):
        # Locate model file
        base_dir = os.path.dirname(os.path.abspath(__file__))
        model_path = os.path.join(base_dir, "model", "ai_detector_model.pkl")

        # Check if model exists
        if not os.path.exists(model_path):
            raise Exception(
                "AI model not found. Please train the model first using:\n"
                "python training/train_ai_model.py"
            )

        logger.info("Loading AI detection model...")
        self.model = joblib.load(model_path)
        
        # Patch for scikit-learn version mismatch
        try:
            if hasattr(self.model, 'named_steps'):
                clf = self.model.named_steps.get('clf')
                if clf and hasattr(clf, 'coef_') and not hasattr(clf, 'multi_class'):
                    clf.multi_class = 'ovr'
            elif hasattr(self.model, 'coef_') and not hasattr(self.model, 'multi_class'):
                self.model.multi_class = 'ovr'
        except Exception as patch_e:
            logger.warning(f"Failed to apply sklearn version patch: {patch_e}")

        logger.info("AI detection model loaded successfully")

        # Minimum text length required
        self.min_text_length = 100

    def calculate_entropy(self, text):
        """
        Calculates Shannon entropy of character distribution.
        """
        if not text: return 0
        prob = [ float(text.count(c)) / len(text) for c in dict.fromkeys(list(text)) ]
        entropy = - sum([ p * math.log(p) / math.log(2.0) for p in prob ])
        return entropy

    def calculate_burstiness(self, text):
        """
        Sentence length variation (Standard Deviation / Mean).
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

    def calculate_lexical_diversity(self, text):
        words = re.findall(r'\b\w+\b', text.lower())
        if not words: return 0
        unique_words = set(words)
        return len(unique_words) / len(words)

    def get_heuristic_score(self, text):
        """
        Calculate AI probability based on heuristics.
        """
        burstiness = self.calculate_burstiness(text)
        entropy = self.calculate_entropy(text)
        lexical_diversity = self.calculate_lexical_diversity(text)

        # Heuristics normalization
        ai_burstiness_score = max(0, min(1, 1.0 - burstiness))
        ai_entropy_score = max(0, min(1, (4.3 - entropy) / 0.8))
        ai_diversity_score = max(0, min(1, (0.6 - lexical_diversity) / 0.4))
        
        # Ensemble Weighted Score
        ai_prob = (ai_burstiness_score * 0.4) + (ai_diversity_score * 0.4) + (ai_entropy_score * 0.2)
        
        # Transition phrase penalization
        ai_phrases = ["in conclusion", "it is important to note", "as an ai", "delve into", "moreover", "furthermore", "tapestry"]
        lower_text = text.lower()
        phrase_count = sum(1 for phrase in ai_phrases if phrase in lower_text)
        ai_prob += (phrase_count * 0.05) 

        return max(0, min(1.0, ai_prob)) * 100

    def detect(self, text: str) -> float:
        """
        Detect AI probability using Hybrid Model (70% Heuristics, 30% ML).
        """
        if not text or len(text.strip()) < self.min_text_length:
            logger.info("Text too short for AI detection")
            return 0.0

        try:
            # 1. ML Model Score (30%)
            model_prob = self.model.predict_proba([text])[0][1]
            model_score = model_prob * 100
            
            # 2. Heuristic Score (70%)
            heuristic_score = self.get_heuristic_score(text)

            # 3. Weighted Result
            final_score = (heuristic_score * 0.7) + (model_score * 0.3)
            final_score = round(max(0, min(100, final_score)), 2)

            logger.info(f"AI Detection Result - Heuristic: {heuristic_score:.2f}%, Model: {model_score:.2f}%, Final: {final_score}%")
            return final_score

        except Exception as e:
            logger.error(f"AI detection failed: {e}")
            return 0.0

    def get_detailed_analysis(self, text: str) -> Dict:
        """
        Return detailed AI detection result with breakdown.
        """
        if not text or len(text.strip()) < self.min_text_length:
            return {
                "ai_probability": 0.0,
                "message": "Text too short",
                "breakdown": {"heuristic_score": 0.0, "model_score": 0.0}
            }

        try:
            model_prob = self.model.predict_proba([text])[0][1]
            model_score = round(model_prob * 100, 2)
            heuristic_score = round(self.get_heuristic_score(text), 2)
            
            final_score = round((heuristic_score * 0.7) + (model_score * 0.3), 2)

            return {
                "ai_probability": final_score,
                "method": "Hybrid (Heuristics + Machine Learning)",
                "breakdown": {
                    "heuristic_score": heuristic_score,
                    "model_score": model_score,
                    "weighting": "70% Heuristic / 30% Model"
                }
            }
        except Exception as e:
            logger.error(f"Detailed analysis failed: {e}")
            return {"error": str(e)}
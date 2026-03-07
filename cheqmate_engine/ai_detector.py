import os
import joblib
import logging
from typing import Dict

# Logging setup
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("AIDetector")


class AIDetector:
    """
    AI Detector using Machine Learning.
    Model: TF-IDF + Logistic Regression
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
        
        # Patch for scikit-learn version mismatch (older model on newer sklearn)
        # Fixes: 'LogisticRegression' object has no attribute 'multi_class'
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
        self.min_text_length = 20

    def detect(self, text: str) -> float:
        """
        Detect AI probability.

        Args:
            text (str): Input text

        Returns:
            float: AI probability percentage (0-100)
        """

        if not text or len(text.strip()) < self.min_text_length:
            logger.info("Text too short for AI detection")
            return 0.0

        try:
            # Predict probability
            probability = self.model.predict_proba([text])[0][1]

            # Convert to percentage
            ai_score = round(probability * 100, 2)

            logger.info(f"AI Detection Score: {ai_score}%")

            return ai_score

        except Exception as e:
            logger.error(f"AI detection failed: {e}")
            return 0.0

    def get_detailed_analysis(self, text: str) -> Dict:
        """
        Return detailed AI detection result.

        Args:
            text (str): Input text

        Returns:
            Dict: Analysis result
        """

        ai_score = self.detect(text)

        return {
            "ai_probability": ai_score,
            "method": "Machine Learning",
            "model": "TF-IDF + Logistic Regression"
        }
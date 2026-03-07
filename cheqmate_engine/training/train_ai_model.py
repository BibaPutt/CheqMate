import pandas as pd
import os
import joblib
from sklearn.model_selection import train_test_split
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.pipeline import Pipeline
from sklearn.metrics import accuracy_score

# Paths
BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATASET_PATH = os.path.join(BASE_DIR, "dataset", "AI_Human.csv")
MODEL_PATH = os.path.join(BASE_DIR, "model", "ai_detector_model.pkl")

print("Loading dataset...")

df = pd.read_csv(DATASET_PATH)

# Columns
X = df["text"]
y = df["generated"]

print("Splitting dataset...")

X_train, X_test, y_train, y_test = train_test_split(
    X, y, test_size=0.2, random_state=42
)

print("Training model...")

model = Pipeline([
    ("tfidf", TfidfVectorizer(stop_words="english", max_features=5000)),
    ("clf", LogisticRegression(max_iter=1000))
])

model.fit(X_train, y_train)

print("Testing model...")

pred = model.predict(X_test)
acc = accuracy_score(y_test, pred)

print(f"Model accuracy: {acc*100:.2f}%")

print("Saving model...")

os.makedirs(os.path.dirname(MODEL_PATH), exist_ok=True)
joblib.dump(model, MODEL_PATH)

print("Model saved to:", MODEL_PATH)
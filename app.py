
import streamlit as st
import pandas as pd
import package as cm  # Importing our package.py
import time

# Page Config
st.set_page_config(
    page_title="CheqMate Prototype",
    page_icon="🛡️",
    layout="wide"
)

# Initialize Engine
@st.cache_resource
def get_engine():
    return cm.CheqMateEngine()

engine = get_engine()

# Title and Description
st.title("🛡️ CheqMate: Advanced Plagiarism & AI Detector")
st.markdown("Upload documents (PDF, DOCX, Images, TXT) to check for plagiarism against the local database and detect AI-generated content.")

# Layout: Split into Upload and Leaderboard
col1, col2 = st.columns([2, 3])

with col1:
    st.header("📤 Submit Assignment")
    uploaded_files = st.file_uploader("Choose files", accept_multiple_files=True)
    
    if uploaded_files:
        for uploaded_file in uploaded_files:
            if st.button(f"Analyze {uploaded_file.name}", key=uploaded_file.name):
                with st.spinner('Processing...'):
                    # Process
                    result = engine.process_submission(uploaded_file, uploaded_file.name)
                    
                    if "error" in result:
                        st.error(result["error"])
                    else:
                        st.success("Analysis Complete!")
                        
                        # Display Results
                        st.subheader(f"Results for: {result['filename']}")
                        
                        # Metrics
                        m1, m2 = st.columns(2)
                        m1.metric("AI Probability", f"{result['ai_score']}%", 
                                 delta_color="inverse" if result['ai_score'] > 50 else "normal",
                                 delta="High Risk" if result['ai_score'] > 70 else None)
                        
                        m2.metric("Plagiarism Score", f"{result['plagiarism_score']}%",
                                 delta_color="inverse" if result['plagiarism_score'] > 20 else "normal",
                                  delta="High Overlap" if result['plagiarism_score'] > 50 else None)
                        
                        # Details
                        if result['details']:
                            st.write("### 🔍 Plagiarism Matches")
                            for match in result['details']:
                                st.warning(f"Match found in **{match['filename']}**: {match['score']}% similarity")
                        else:
                            st.info("No significant plagiarism detected from other submissions.")
                        
                        # Text Preview
                        with st.expander("Show Extracted Text"):
                            st.text(result['text_preview'])

with col2:
    st.header("🏆 Live Dashboard")
    st.markdown("Real-time ranking of submissions by Plagiarism Score.")
    
    if st.button("Refresh Dashboard"):
        st.rerun()

    # Get Data
    data = engine.get_leaderboard_data()
    
    if data:
        df = pd.DataFrame(data)
        
        # Styling
        st.dataframe(
            df,
            column_config={
                "AI Probability": st.column_config.ProgressColumn(
                    "AI Probability",
                    help="Probability of being AI generated",
                    format="%.2f%%",
                    min_value=0,
                    max_value=100,
                ),
                "Plagiarism Score": st.column_config.ProgressColumn(
                    "Plagiarism Score",
                    help="Max similarity with others",
                    format="%.2f%%",
                    min_value=0,
                    max_value=100,
                ),
            },
            hide_index=True,
            use_container_width=True
        )
    else:
        st.info("No submissions yet.")

# Footer
st.markdown("---")
st.caption("CheqMate Prototype v0.1 | Local Offline Mode")

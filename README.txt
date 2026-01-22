# CheqMate - Installation & Setup Instructions

CheqMate is a plagiarism and AI detection system for Moodle. It consists of two main parts:
1. **CheqMate Plugin**: A Moodle assignment submission plugin.
2. **CheqMate Engine**: A Python-based FastAPI backend that handles document processing and analysis.

---

## 1. Moodle Plugin Installation

Follow these steps to install the plugin in your Moodle environment:

1.  Locate the folder named `cheqmate_plugin` in this project.
2.  Navigate to your Moodle installation directory: `moodle/mod/assign/submission/`.
3.  Copy the `cheqmate_plugin` folder into that directory.
4.  **RENAME** the copied folder to `cheqmate`.
    *   Path should look like: `moodle/mod/assign/submission/cheqmate`
5.  Log in to your Moodle site as an Administrator.
6.  Go to **Site administration > Notifications**. Moodle will detect the new plugin and prompt you to install it. Follow the on-screen instructions.

---

## 2. Setting Up the CheqMate Engine (Python Backend)

The engine must be running for Moodle to perform plagiarism and AI checks.

### Prerequisites
*   Python 3.8 or higher installed.
*   Pip (Python package manager).

### Installation Steps

1.  Open a terminal or command prompt (PowerShell/CMD).
2.  Navigate to the `cheqmate_engine` directory:
    ```bash
    cd cheqmate_engine
    ```
3.  Install the required dependencies:
    ```bash
    pip install -r requirements.txt
    ```

### Running the Engine

You can start the engine using either of the following methods:

**Method A: Using Pip (Uvicorn directly)**
```bash
uvicorn app:app --host 0.0.0.0 --port 8000 --reload
```

**Method B: Using Python Launcher (Windows)**
```bash
py -m uvicorn app:app --host 0.0.0.0 --port 8000 --reload
```

**Method C: Using Standard Python**
```bash
python -m uvicorn app:app --host 0.0.0.0 --port 8000 --reload
```

The engine will start at `http://localhost:8000`. 

---

## 3. Troubleshooting

### ImportError: DLL load failed (PyMuPDF / NumPy / fitz)
If you see an error like `ImportError: DLL load failed while importing _extra` or `_multiarray_umath` on Windows:

1.  **Install Microsoft Visual C++ Redistributable (REQUIRED)**:
    This is the most common cause. Download and install the latest X64 version from: [https://aka.ms/vs/17/release/vc_redist.x64.exe](https://aka.ms/vs/17/release/vc_redist.x64.exe)
    *   *Note: You must restart your terminal (and possibly your computer) after installation.*

2.  **Reinstall Dependencies**:
    If the error persists after installing the Redistributable, run:
    ```bash
    pip install --force-reinstall pymupdf numpy
    ```

3.  **Restart the Engine**:
    Try running the engine again.

> [!NOTE]
> Make sure the URL in your Moodle plugin settings matches the address where this engine is running.


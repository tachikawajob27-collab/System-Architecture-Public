
import os
import json
import logging
from google.oauth2.credentials import Credentials
from google.auth.transport.requests import Request
from googleapiclient.discovery import build

logger = logging.getLogger(__name__)

TOKEN_PATH = os.path.join(os.path.dirname(os.path.dirname(__file__)), "google_token.json")

def get_google_creds() -> Credentials:
    if not os.path.exists(TOKEN_PATH):
        raise FileNotFoundError(f"Google token file not found at {TOKEN_PATH}. Please run setup_google_auth.py first.")
        
    with open(TOKEN_PATH) as f:
        token_data = json.load(f)
    
    creds = Credentials(
        token=token_data.get("token"),
        refresh_token=token_data.get("refresh_token"),
        token_uri=token_data.get("token_uri"),
        client_id=os.getenv("GOOGLE_CLIENT_ID"),
        client_secret=os.getenv("GOOGLE_CLIENT_SECRET"),
        scopes=token_data.get("scopes"),
    )
    
    if not creds.valid:
        if creds and creds.expired and creds.refresh_token:
            creds.refresh(Request())
            with open(TOKEN_PATH, "w") as f:
                f.write(creds.to_json())
        else:
            raise Exception("Google credentials expired and could not be refreshed.")
            
    return creds

class GoogleDocsManager:
    def __init__(self):
        self.creds = get_google_creds()
        self.docs_service = build("docs", "v1", credentials=self.creds)
        self.drive_service = build("drive", "v3", credentials=self.creds)

    def create_doc(self, title: str) -> str:
        doc = self.docs_service.documents().create(body={"title": title}).execute()
        return doc.get("documentId")

    def set_anyone_viewer(self, file_id: str):
        self.drive_service.permissions().create(
            fileId=file_id, body={"type": "anyone", "role": "reader"}
        ).execute()

    def batch_update(self, document_id: str, requests: list):
        if not requests:
            return
        return self.docs_service.documents().batchUpdate(
            documentId=document_id, body={"requests": requests}
        ).execute()

    def insert_table(self, document_id: str, index: int, rows: int, columns: int):
        request = {
            "insertTable": {
                "rows": rows,
                "columns": columns,
                "location": {"index": index}
            }
        }
        return self.batch_update(document_id, [request])

    def get_document(self, document_id: str):
        return self.docs_service.documents().get(documentId=document_id).execute()

    def move_to_folder(self, file_id: str, folder_name: str):
        try:
            query = f"mimeType = 'application/vnd.google-apps.folder' and name = '{folder_name}' and trashed = false"
            search_res = self.drive_service.files().list(q=query, fields="files(id, name)").execute()
            folders = search_res.get("files", [])
            if folders:
                folder_id = folders[0]["id"]
                file_meta = self.drive_service.files().get(fileId=file_id, fields="parents").execute()
                previous_parents = ",".join(file_meta.get("parents", []))
                self.drive_service.files().update(
                    fileId=file_id,
                    addParents=folder_id,
                    removeParents=previous_parents,
                    fields="id, parents"
                ).execute()
                logger.info(f"Moved file {file_id} to folder '{folder_name}' ({folder_id})")
            else:
                logger.warning(f"Folder '{folder_name}' not found. File left in root.")
        except Exception as e:
            logger.error(f"Error moving file {file_id} to folder '{folder_name}': {e}")

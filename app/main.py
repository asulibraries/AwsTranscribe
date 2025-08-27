from fastapi import FastAPI, Request, Header, HTTPException, Response
import hashlib
import logging
import os
import time
import requests
import boto3
from urllib.parse import urlparse, unquote

app = FastAPI()

# Configuration from environment
REGION = os.getenv("AWS_REGION", "us-west-2")
KEEP_PREFIX = os.getenv("KEEP_S3_PREFIX", "keep-private/")
PRISM_PREFIX = os.getenv("PRISM_S3_PREFIX", "prism-private/")
POLL_INTERVAL = float(os.getenv("TRANSCRIBE_POLL_INTERVAL", "5"))
LANGUAGES = os.getenv("TRANSCRIBE_LANGUAGES", "auto").split(",")



# Logger
logger = logging.getLogger("aws_transcribe")
logger.setLevel(logging.INFO)
handler = logging.StreamHandler()
logger.addHandler(handler)

# Detect if AWS credentials are set in environment variables
aws_access_key = os.getenv("AWS_ACCESS_KEY_ID")
aws_secret_key = os.getenv("AWS_SECRET_ACCESS_KEY")
S3_BUCKET = os.getenv("S3_BUCKET")
region = os.getenv("AWS_REGION", "us-west-2")

@app.get("/")
async def health_check():
    if aws_access_key and aws_secret_key:
        s3_client = boto3.client(
            "s3",
            aws_access_key_id=aws_access_key,
            aws_secret_access_key=aws_secret_key,
            region_name=REGION,
        )
        transcribe_client = boto3.client(
            "transcribe",
            aws_access_key_id=aws_access_key,
            aws_secret_access_key=aws_secret_key,
            region_name=REGION,
        )
    else:
        s3_client = boto3.client("s3", region_name=REGION)
        transcribe_client = boto3.client("transcribe", region_name=REGION)
    # 1. Check S3 bucket exists
    try:
        s3_client.head_bucket(Bucket=S3_BUCKET)
    except Exception as e:
        raise HTTPException(
            status_code=500,
            detail=f"S3 bucket '{S3_BUCKET}' is not accessible: {e}"
        )

    # 2. Check AWS Transcribe access by listing jobs (minimal call)
    try:
        transcribe_client.list_transcription_jobs(MaxResults=1)
    except Exception as e:
        raise HTTPException(
            status_code=500,
            detail=f"AWS Transcribe access failed: {e}"
        )

    return Response(f"Health OK. S3 bucket exists and Transcribe credentials work.", media_type="text/plain")


@app.get("/transcribe")
async def transcribe_endpoint(
    request: Request,
    apix_ldp_resource: str = Header(..., alias="Apix-Ldp-Resource"),
):

    if aws_access_key and aws_secret_key:
        # Local development: use credentials from environment
        transcribe = boto3.client(
            "transcribe",
            aws_access_key_id=aws_access_key,
            aws_secret_access_key=aws_secret_key,
            region_name=region
        )
    else:
        # ECS / IAM role: use automatic credentials
        transcribe = boto3.client("transcribe", region_name=region)

    parsed = urlparse(apix_ldp_resource)
    host = parsed.netloc
    path = unquote(parsed.path.lstrip('/'))
    if host.startswith("keep") and "lib.asu.edu" in host:
        s3_key = f"s3://{S3_BUCKET}/{KEEP_PREFIX}{path}"
    elif host.startswith("prism") and "lib.asu.edu" in host:
        s3_key = f"s3://{S3_BUCKET}/{PRISM_PREFIX}{path}"
    elif "cloudfront.net" in host:
        s3_key = f"s3://{S3_BUCKET}/{path}"
    else:
        raise HTTPException(
            status_code=400,
            detail=f"Unrecognized host in Apix-Ldp-Resource URL: {host}"
        )

    digest = hashlib.md5(s3_key.encode()).hexdigest()
    logger.info(f"Starting transcription for resource: {apix_ldp_resource}")
    logger.info(f"Mapped to S3 key / job name: {s3_key} / {digest}")

    language_code = LANGUAGES[0].strip()

    try:
        transcribe.get_transcription_job(TranscriptionJobName=digest)
        logger.info("Job already exists, will poll for completion.")
    except transcribe.exceptions.BadRequestException:
        logger.info("Starting new transcription job...")
        transcribe.start_transcription_job(
            TranscriptionJobName=digest,
            LanguageCode=language_code,
            Media={"MediaFileUri": s3_key},
            Subtitles={"Formats": ["vtt"], "OutputStartIndex": 1},
        )

    while True:
        status_resp = transcribe.get_transcription_job(TranscriptionJobName=digest)
        status = status_resp["TranscriptionJob"]["TranscriptionJobStatus"]
        if status == "COMPLETED":
            logger.info("Transcription job completed!")
            break
        if status == "FAILED":
            reason = status_resp["TranscriptionJob"]["FailureReason"]
            logger.error(f"Transcription job failed: {reason}")
            raise HTTPException(status_code=500, detail=reason)
        logger.info(f"Waiting for transcription to complete... (poll every {POLL_INTERVAL}s)")
        time.sleep(POLL_INTERVAL)

    subtitle_uri = status_resp["TranscriptionJob"]["Subtitles"]["SubtitleFileUris"][0]
    r = requests.get(subtitle_uri)
    r.raise_for_status()
    vtt_content = r.content

    return Response(vtt_content, media_type="text/vtt")

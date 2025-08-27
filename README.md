AWS Transcribe
==============

Islandora Microservice to generate WebVTT files using AWS Transcribe using their existing S3 location.

## Environment Variables

| Env Var                    | Purpose                                                      |
| -------------------------- | ------------------------------------------------------------ |
| `AWS_REGION`               | AWS region (default: `us-west-2`)                            |
| `S3_BUCKET`                | Bucket holding the files.                                    |
| `KEEP_S3_PREFIX`           | S3 prefix for keep.lib.asu.edu files                         |
| `PRISM_S3_PREFIX`          | S3 prefix for prism.lib.asu.edu files                        |
| `TRANSCRIBE_POLL_INTERVAL` | Poll interval in seconds for AWS job completion (default: 5) |
| `TRANSCRIBE_LANGUAGES`     | OPTIONAL comma-separated list of AWS-supported languages     |
| `AWS_ACCESS_KEY_ID`        | OPTIONAL for development                                     |
| `AWS_SECRET_ACCESS_KEY`    | OPTIONAL for development                                     |

## Local Development

1. Clone the repo
    ```bash
    git clone <repo-url>
    cd AwsTranscribe
    ```
1. Copy the `.env.example` to `.env` and update the values
1. Build and run in docker:
    ```bash
    docker build -t transcribe .
    docker run --env-file .env -p 8000:8000 transcribe
    ```
1. Test endpoints
  - Healthcheck: `curl localhost:8000/`
  - Transcribe: `curl -H "Apix-Ldp-Resource: <media url>" localhost:8000/transcribe -o test.vtt`

## Deployment

1. Build & Push Docker Image to ECR
    ```
    aws ecr get-login-password --region us-west-2 | \
    docker login --username AWS --password-stdin <account-id>.dkr.ecr.us-west-2.amazonaws.com

    aws ecr create-repository --repository-name islandora/transcribe

    docker build -t islandora/transcribe .
    docker tag islandora/transcribe:latest <account-id>.dkr.ecr.us-west-2.amazonaws.com/islandora/transcribe:latest
    docker push <account-id>.dkr.ecr.us-west-2.amazonaws.com/islandora/transcribe:latest
    ```
1. Create IAM Task Role (provides credentials automatically; no `.env` credentials)
    1. IAM → Roles → Create Role → Elastic Container Service → ECS Task.
    1. Attach policies:
        - `AmazonS3ReadOnlyAccess` (or scoped to your bucket)
        - `AmazonTranscribeFullAccess`
    1. Name: `ecs-transcribe-task-role`.
1. ECS Task Definition (Fargate) sample json:
    ```json
    {
    "family": "islandora-transcribe",
    "networkMode": "awsvpc",
    "requiresCompatibilities": ["FARGATE"],
    "cpu": "512",
    "memory": "1024",
    "taskRoleArn": "arn:aws:iam::<account-id>:role/ecs-transcribe-task-role",
    "executionRoleArn": "arn:aws:iam::<account-id>:role/ecsTaskExecutionRole",
    "containerDefinitions": [
        {
        "name": "islandora-transcribe",
        "image": "<account-id>.dkr.ecr.us-west-2.amazonaws.com/islandora/transcribe:latest",
        "essential": true,
        "healthCheck": {
            "command": [
                "CMD-SHELL",
                "curl -f http://localhost:8000/ || exit 1"
            ],
            "interval": 30,
            "retries": 3,
            "timeout": 5
        },
        "portMappings": [
            {
            "containerPort": 8000,
            "hostPort": 8000,
            "protocol": "tcp"
            }
        ],
        "environment": [
            {"name": "S3_BUCKET", "value": "my-transcribe-bucket"},
            {"name": "KEEP_PREFIX", "value": "keep-private/"},
            {"name": "PRISM_PREFIX", "value": "prism-private/"},
            {"name": "TRANSCRIBE_POLL_INTERVAL", "value": "5"},
            {"name": "AWS_REGION", "value": "us-west-2"}
        ]
        }
    ]
    }
    ```
1. Deploy ECS Service
    1. Go to your ECS cluster → Create Service.
    1. Launch type: Fargate.
    1. Select the task definition above.
    1. Desired number of tasks: e.g., 1.
    1. Optional: configure load balancer for public access.

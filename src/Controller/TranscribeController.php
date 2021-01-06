<?php

namespace App\Controller;

use Aws\TranscribeService\TranscribeServiceClient;
use Aws\S3\S3Client;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Transcribe Controller.
 */
class TranscribeController {

  /**
   * @var \Psr\Log\LoggerInterface
   *   The logger.
   */
  protected $log;

  /**
   * @var string
   *   The fedora base url.
   */
  protected $fedoraBaseUrl;

  /**
   * @var string
   *   The s3 bucket.
   */
  protected $s3Bucket;

  /**
   * Controller constructor.
   *
   * @param \Psr\Log\LoggerInterface $log
   *   The logger.
   * @param string $fedoraBaseUrl
   *   The fedora base url.
   * @param string $s3Bucket
   *   The s3 bucket
   */
  public function __construct(
    LoggerInterface $log,
    string $fedoraBaseUrl,
    string $s3Bucket
  ) {
    $this->log = $log;
    $this->fedoraBaseUrl = $fedoraBaseUrl;
    $this->s3Bucket = $s3Bucket;
  }

  /**
   * Index.
   */
  public function index(): Response {
    return new Response('<html><body>AWS Transcribe microservice is up and running!</body></html');
  }

  /**
   * Random number function.
   */
  public function number(): Response {
    $number = random_int(0, 100);
    return new Response('<html><body>' . $number . '</body></html>');
  }

  /**
   * Get Transcriptions method.
   */
  public function getTranscriptions(): Response {
    $client = new TranscribeServiceClient([
      'version' => 'latest',
      'region' => 'us-east-1',
      'profile' => 'default',
    ]);
    $result = $client->listTranscriptionJobs();
    return new Response($result);
  }

  /**
   * Create Transcription job.
   */
  public function createJob(): Response {
    $client = new TranscribeServiceClient([
      'version' => 'latest',
      'region' => 'us-east-1',
    ]);
    $result = $client->startTranscriptionJob([
      'TranscriptionJobName' => 'test1',
      'Media' => [
        'MediaFileUri' => 's3://fcrepo-filestore/19f6648a2fe6f51d228faccd658f77304fd50a3e',
      ],
      'LanguageCode' => 'en-US',
    ]);
    return new Response($result);
  }

  /**
   * Get Transcription method.
   */
  public function getTranscription(string $job_name): Response {
    $result = $this->getTranscriptJobInfo($job_name);
    return new Response($result);
  }

  /**
   * Get json output.
   */
  public function getTranscriptJson(string $job_name): Response {
    $result = $this->getTranscriptJobInfo($job_name);
    $json_url = $result['TranscriptionJob']['Transcript']['TranscriptFileUri'];
    $client = new Client();
    $json = $client->get($json_url);
    return new Response($json->getBody());
  }

  /**
   * Get the transcription job info.
   */
  private function getTranscriptJobInfo(string $job_name) {
    $client = new TranscribeServiceClient([
      'version' => 'latest',
      'region' => 'us-east-1',
    ]);
    $result = $client->getTranscriptionJob([
      'TranscriptionJobName' => $job_name,
    ]);
    return $result;
  }

  /**
   * Start the derivative job from Drupal.
   */
  public function startJobFromDrupal(Request $request) {
    $this->log->info('Caption request.');
    $this->log->info($request->headers->get('Apix-Ldp-Resource'));
    $fedora_url = $request->headers->get('Apix-Ldp-Resource');
    $this->log->info($this->fedoraBaseUrl);
    $url_parts = explode('fedora', $fedora_url);
    // $this->log->info();
    $fedora_uri = $this->fedoraBaseUrl . end($url_parts);
    $client = new Client();
    $fedora_info = $client->get($fedora_uri, ["headers" => ["Want-Digest" => "sha"]]);
    $this->log->info($fedora_info->getBody());
    $this->log->info(print_r($fedora_info->getHeaders(), TRUE));
    $digest = $fedora_info->getHeader('Digest')[0];
    $digest = str_replace('sha=', '', $digest);
    $digest = str_replace('sha%3D', '', $digest);
    $this->log->info($digest);
    // $s3Client = new S3Client([
    //   'version' => 'latest',
    //   'region' => 'us-east-1',
    // ]);
    // $s3_info = $s3Client->headObject([
    //   'Bucket' => $this->s3Bucket,
    //   'Key' => $digest,
    //   ]);
    // $this->log->info(print_r($s3_info, TRUE));
    $transcribeClient = new TranscribeServiceClient([
      'version' => 'latest',
      'region' => 'us-east-1',
    ]);

    // Return response.
    try {
      $result = $transcribeClient->startTranscriptionJob([
        'TranscriptionJobName' => $digest,
        'Media' => [
          'MediaFileUri' => 's3://fcrepo-filestore/' . $digest,
        ],
        'LanguageCode' => 'en-US',
      ]);
      $this->log->info(print_r($result, TRUE));
      $status = [];
      while (TRUE) {
        $status = $transcribeClient->getTranscriptionJob([
          'TranscriptionJobName' => $digest,
        ]);
        $this->log->info($status->get('TranscriptionJob')['TranscriptionJobStatus']);

        if ($status->get('TranscriptionJob')['TranscriptionJobStatus'] == 'COMPLETED') {
          break;
        }
        if ($status->get('TranscriptionJob')['TranscriptionJobStatus'] == 'FAILED') {
          $this->log->error("RuntimeException:", ['exception' => "AWS Transcription JOB Failed"]);
          $this->log->error($status->get('TranscriptionJob')['FailureReason']);
          return new Response($status->get('TranscriptionJob')['FailureReason'], 500);
        }

        sleep(5);
      }

      // If we made it here the job completed successfully.
      $this->log->info("transcription job completed");
      $transcript_url = $status->get('TranscriptionJob')['Transcript']['TranscriptFileUri'];
      $client = new Client();
      $json = $client->get($transcript_url);
      $json_body = $json->getBody();
      $infile = "/var/www/AwsTranscribe/var/infiles/" . $digest . "_infile.json";
      $fp = fopen($infile, 'w');
      fwrite($fp, json_encode($json_body));
      fclose($fp);
      $this->log->info("wrote the transcript json file to disk");
      $outfile = "/var/www/AwsTranscribe/var/outfiles/" . $digest . "_outfile.srt";
      $py_command = "python3 awstosrt.py " . $infile . " " . $outfile;
      try {
        exec($py_command, $output, $retval);
        $this->log->info("Python script returned with status " . $retval . " and output: \n");
        $this->log->info(print_r($output, TRUE));
        return new Response(
          file_get_contents($outfile),
          200,
          ['Content-Type' => 'text/plain']
        );
      }
      catch (\RuntimeException $e) {
        $this->log->error("RuntimeException:", ['exception' => $e]);
	$this->log->error("Failed executing python script");
        return new Response($e->getMessage(), 500);
      }
    }
    catch (\RuntimeException $e) {
      $this->log->error("RuntimeException:", ['exception' => $e]);
      $this->log->error("Failed to start and  get the transcription job results and process them");
      return new Response($e->getMessage(), 500);
    }

  }

}

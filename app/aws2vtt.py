import json
import sys
from datetime import timedelta


def format_time(seconds: float) -> str:
    """Convert seconds to WebVTT timestamp (HH:MM:SS.mmm)."""
    td = timedelta(seconds=seconds)
    total_seconds = int(td.total_seconds())
    hours, remainder = divmod(total_seconds, 3600)
    minutes, secs = divmod(remainder, 60)
    millis = int(round((seconds - int(seconds)) * 1000))
    return f"{hours:02}:{minutes:02}:{secs:02}.{millis:03}"


class AWSTranscribeParser:
    def __init__(self, data, min_words=8, max_words=12):
        self.data = data
        self.min_words = min_words
        self.max_words = max_words
        self.subtitles = []

    def parse(self):
        items = self.data.get("results", {}).get("items", [])
        current_start = None
        current_end = None
        current_words = []
        word_count = 0

        for item in items:
            content = item["alternatives"][0]["content"]

            if item.get("type") == "punctuation":
                if current_words:
                    # Attach punctuation to last word.
                    current_words[-1] += content
                else:
                    # Append to the previous line if no current words.
                    self.subtitles[-1][2] += content
                    continue
            else:
                start_time = float(item.get("start_time", 0))
                end_time = float(item.get("end_time", 0))

                if current_start is None:
                    current_start = start_time
                current_end = end_time
                current_words.append(content)
                word_count += 1

            if word_count >= self.max_words or (word_count >= self.min_words and content in {".", "!", "?"}):
                self.subtitles.append(
                    [current_start, current_end, " ".join(current_words)]
                )
                current_start = None
                current_end = None
                current_words = []
                word_count = 0

        # Handle any leftover words.
        if current_words:
            self.subtitles.append([current_start, current_end, " ".join(current_words)])

    def to_webvtt_string(self) -> str:
        """Return WebVTT as a string."""
        lines = ["WEBVTT\n"]
        for start, end, text in self.subtitles:
            lines.append(f"{format_time(start)} --> {format_time(end)}")
            lines.append(text)
            lines.append("")  # blank line between cues
        return "\n".join(lines)

    def write_webvtt(self, outfile: str):
        """Write WebVTT to a file."""
        with open(outfile, "w", encoding="utf-8") as f:
            f.write(self.to_webvtt_string())


def aws_transcribe_to_webvtt(infile: str, outfile: str, min_words=8, max_words=12):
    with open(infile, "r") as f:
        data = json.load(f)

    parser = AWSTranscribeParser(data, min_words, max_words)
    parser.parse()
    parser.write_webvtt(outfile)


def aws_transcribe_to_webvtt_string(infile: str, min_words=8, max_words=12) -> str:
    """Convert AWS Transcribe JSON to WebVTT and return as a string."""
    with open(infile, "r") as f:
        data = json.load(f)

    parser = AWSTranscribeParser(data, min_words, max_words)
    parser.parse()
    return parser.to_webvtt_string()


if __name__ == "__main__":
    if len(sys.argv) < 3:
        print("Usage: python awstovtt.py input.json output.vtt [min_words] [max_words]")
        sys.exit(1)

    infile, outfile = sys.argv[1], sys.argv[2]
    min_words = int(sys.argv[3]) if len(sys.argv) > 3 else 8
    max_words = int(sys.argv[4]) if len(sys.argv) > 4 else 12

    aws_transcribe_to_webvtt(infile, outfile, min_words, max_words)
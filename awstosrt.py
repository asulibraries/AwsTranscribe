import re
import sys
import json
import logging
from datetime import timedelta

import srt

log = logging.getLogger(__name__)
log.addHandler(logging.StreamHandler())
log.setLevel(logging.DEBUG)

''' Parameters to control subtitle behavior.
See http://bbc.github.io/subtitle-guidelines/ for recommended practices.
'''
MAX_LEN = 74  # Maximum characters per screen (37 * 2)
MAX_LINE_LEN = 37  # Maximum characters per line
INTERVAL = 2000  # Minimum time in ms between lines
CC_TIME_WINDOW = 500  # Line combining window in ms
MAX_TIME = 8  # Seconds any line should persist on-screen


class TranscribeToSRT():

    def __init__(self, infile, outfile):
        self.srt = []
        self.subtitles = []
        self.last = timedelta(seconds=0)
        self.outfile = outfile
        f = open(infile, 'r')

        self.data = json.loads(f.read())

        self.items = self.data['results']['items']
        log.debug("{} items found.".format(len(self.items)))

    def to_delta(self, str_seconds, int_offset_secs=0):
        ''' Transform a string representing seconds in float (e.g. ss.mmm) to a
        timedelta object'''
        flt_s = float(str_seconds)
        int_s = int(flt_s)
        int_ms = int((flt_s - int_s) * 1000.0)
        return timedelta(seconds=int_s + int_offset_secs, milliseconds=int_ms)

    def get_last(self, i):
        ''' Return the timestamp (as a timedelta) of the last transcribed word
            and update the pointer to this one if possible.'''
        old_last = self.last
        if 'start_time' in i:
            self.last = self.to_delta(i['start_time'])
        return old_last

    def get_start(self, i):
        try:
            if 'start_time' in i:
                return self.to_delta(i['start_time'])
            else:
                return self.get_last(i)
        except:
            log.exception('{}'.format(i))

    def get_text(self, i):
        return i['alternatives'][0]['content']

    ''' SRT lines are up to MAX_LEN (usually 74) characters.  Break lines on
        punctuation, line length, and intervals between words.
    '''

    def parse(self):
        line = ''
        start = None

        for n, i in enumerate(self.items):
            text = self.get_text(i)

            if len(line) == 0:  # New line.  Start subtitle timestamp.
                start = self.get_start(i)

            # If n-1 is the length of self.items, we've hit the end of the list
            if n + 1 == len(self.items):
                line += text
                self._add_line(line, start, self.get_last(
                    i) + timedelta(milliseconds=INTERVAL))
                continue

            # If the text is a period and the line will be over MAX_LEN with the next word, end it.
            if text == '.' and len(line) + len(self.get_text(self.items[n + 1])) > MAX_LEN:
                line += text
                log.debug("Hit period at end of line.")
                self._add_line(line, start, self.get_last(
                    i) + timedelta(milliseconds=INTERVAL))
                line = ''
                continue

            # If the time elapsed since the last word is > INTERVAL ms, go to a new line.
            if (self.get_start(i) - self.get_last(i)).total_seconds() > INTERVAL / 1000:
                log.debug("Interval exceeded")
                self._add_line(line, start, self.get_last(
                    i) + timedelta(milliseconds=INTERVAL))
                line = text
                start = self.get_start(i)
                continue

            if len(line) + len(text) < MAX_LEN:  # Add it to the line
                if i['type'] == 'punctuation':  # No space before punctuation
                    line += text
                else:
                    line += ' {}'.format(text)
            else:  # Line is long enough.  Commit it.
                self._add_line(line, start, self.get_last(
                    i) + timedelta(milliseconds=INTERVAL))
                line = text
                if 'start_time' in i:
                    start = self.to_delta(i['start_time'])
                else:
                    start = self.get_last(i)
            self.get_last(i)

    def _add_line(self, text, start, end):
        ''' As each line comes in, set and/or correct the timing.
        Algorithmically, subtitles are to be on the screen no more than
        2 at a time, and for up to MAX_TIME seconds.  Since each line comes
        individually from the VBI parser, any lines that arrive within
        half of a second should be consolidated into one SRT entry.
        Subsequent entries should end the previous entry if it comes
        less than 5 seconds after it.

        Check that the next entry for the start time and set the end 1 frame
        (~33ms) before it. '''
        if len(self.srt) == 0:  # First line
            self.srt.append(srt.Subtitle(index=1,
                                         start=start,
                                         end=end,
                                         content=text))
            log.debug("Add: {}".format(self.srt[-1]))
            return

        # Line-combining threshold
        delta = timedelta(milliseconds=CC_TIME_WINDOW)

        if start < self.srt[-1].start + delta:  # Is it within the time window?
            # Combine
            self.srt[-1].content = '{}\n{}'.format(self.srt[-1].content, text)
            log.debug("Combine: {}".format(self.srt[-1]))
        else:  # It is outside the time window
            # Previous entry is too long
            if self.srt[-1].end > self.srt[-1].start + timedelta(seconds=MAX_TIME):
                _e_time = self.srt[-1].start + timedelta(seconds=MAX_TIME)
                _redux = (self.srt[-1].end - _e_time).total_seconds() * 1000
                _total = (_e_time - self.srt[-1].start).total_seconds() * 1000
                log.debug("Length set to {} (removed {}ms, {}ms total display time)".format(
                    _e_time, _redux, _total))
                self.srt[-1].end = _e_time  # So fix it

            if self.srt[-1].end > start:  # Previous entry ends past what we're adding
                f_time = start - timedelta(milliseconds=33)
                _redux = (f_time - self.srt[-1].end).total_seconds() * 1000
                _total = (f_time - self.srt[-1].start).total_seconds() * 1000
                log.debug("End timestamp reduced to {} ({}ms, {}ms total display time)".format(
                    f_time, _redux, _total))
                self.srt[-1].end = f_time  # So fix it

            if len(text) > MAX_LINE_LEN and '\n' not in text:  # Break the line if not already split
                tlist = str.split(text)
                tout = ''
                for i, t in enumerate(tlist):
                    if i == 0:  # First word
                        tout = t
                    elif len(tout) + len(t) <= MAX_LINE_LEN:
                        tout += ' {}'.format(t)
                    else:
                        tout += '\n{}'.format(' '.join(tlist[i:]))
                        break
                log.debug("Split line longer than {} characters:\n{}==>\n{}".format(
                    MAX_LINE_LEN, text, tout))
                text = tout  # This could be assigned above, but is done here for the debug line above

            # Add the new entry to the SRT list
            self.srt.append(srt.Subtitle(index=len(self.srt) + 1,
                                         start=start,
                                         end=end,
                                         content=text))
            log.debug("Add: {}".format(self.srt[-1]))

        #if len(self.srt) > 10:
        #    quit()

    def write(self, filename=None):
        if not filename:
            filename = self.outfile
        f = open(filename, 'w')
        f.write(srt.compose(self.srt))
        f.flush()
        f.close()


if __name__ == "__main__":
    infile = sys.argv[1]
    outfile = sys.argv[2]
    t = TranscribeToSRT(infile, outfile)
    t.parse()
    t.write()

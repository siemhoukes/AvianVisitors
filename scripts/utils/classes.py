import datetime
import os
import re

from tzlocal import get_localzone

from .helpers import birdnet_week


class Detection:
    def __init__(self, file_date, start_time, stop_time, scientific_name, common_name, confidence):
        self.start = float(start_time)
        self.stop = float(stop_time)
        self.datetime = file_date + datetime.timedelta(seconds=self.start)
        self.date = self.datetime.strftime("%Y-%m-%d")
        self.time = self.datetime.strftime("%H:%M:%S")
        self.iso8601 = self.datetime.astimezone(get_localzone()).isoformat()
        # ISO week (1..53) on purpose: this one is only *recorded* - it lands
        # in the detections.Week column, the BirdWeather POST and the Apprise
        # $week token, all of which have carried an ISO week since forever.
        # It never reaches a model. The week that does is ParseFileName.week
        # below, which must be BirdNET's 1..48 instead.
        self.week = self.datetime.isocalendar()[1]
        self.confidence = round(float(confidence), 4)
        self.confidence_pct = round(self.confidence * 100)
        self.species = scientific_name
        self.scientific_name = scientific_name
        self.common_name = common_name
        self.common_name_safe = self.common_name.replace("'", "").replace(" ", "_")
        self.file_name_extr = None

    def __str__(self):
        return f'Detection({self.species}, {self.common_name}, {self.confidence}, {self.iso8601})'


class ParseFileName:
    def __init__(self, file_name):
        self.file_name = file_name
        name = os.path.splitext(os.path.basename(file_name))[0]
        date_created = re.search('^[0-9]+-[0-9]+-[0-9]+', name).group()
        time_created = re.search('[0-9]+:[0-9]+:[0-9]+$', name).group()
        self.file_date = datetime.datetime.strptime(f'{date_created}T{time_created}', "%Y-%m-%dT%H:%M:%S")
        self.root = name

        ident_match = re.search("RTSP_[0-9]+-", file_name)
        self.RTSP_id = ident_match.group() if ident_match is not None else ""

    @property
    def iso8601(self):
        current_iso8601 = self.file_date.astimezone(get_localzone()).isoformat()
        return current_iso8601

    @property
    def week(self):
        # THIS is the week the analyzer feeds to the model
        # (analysis.py -> analyzeAudioData -> set_meta_data), so it must be
        # BirdNET's 1..48. It used to be isocalendar()[1], which meant that
        # every late December the species filter ran out of distribution and
        # let through roughly twice as many species as it should. See
        # birdnet_week().
        return birdnet_week(self.file_date)

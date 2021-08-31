<?php
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE HTML>
<html>

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />
    <meta http-equiv="Pragma" content="no-cache" />
    <meta http-equiv="Expires" content="0" />
    <title>scheduled events calendar</title>
    <script src="https://uicdn.toast.com/tui.code-snippet/latest/tui-code-snippet.js"></script>
    <script src="https://uicdn.toast.com/tui-calendar/latest/tui-calendar.js"></script>
    <script src="./sessionsmaker/sessions.js"></script>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.2/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="https://uicdn.toast.com/tui.time-picker/latest/tui-time-picker.css">
    <link rel="stylesheet" type="text/css" href="https://uicdn.toast.com/tui.date-picker/latest/tui-date-picker.css">
    <link rel="stylesheet" type="text/css" href="https://uicdn.toast.com/tui-calendar/latest/tui-calendar.css" />
    <link rel="stylesheet" type="text/css" href="./css/default.css">
    <link rel="stylesheet" type="text/css" href="./css/icons.css">
    <script src="https://code.jquery.com/jquery-3.2.1.slim.min.js"
        integrity="sha384-KJ3o2DKtIkvYIK3UENzmM7KCkRr/rE9/Qpg6aAZGJwFDMVNA/GpGFF93hXpG5KkN"
        crossorigin="anonymous"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.2/js/bootstrap.min.js"></script>
    <script src="https://uicdn.toast.com/tui.code-snippet/v1.5.2/tui-code-snippet.min.js"></script>
    <script src="https://uicdn.toast.com/tui.time-picker/v2.0.3/tui-time-picker.min.js"></script>
    <script src="https://uicdn.toast.com/tui.date-picker/v4.0.3/tui-date-picker.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.20.1/moment.min.js"></script>
    <script src="https://momentjs.com/downloads/moment-timezone-with-data.js"></script>
    <style>
        .tui-full-calendar-popup-detail .tui-full-calendar-content {
            height: 70px !important;
        }

        .popupdescriptionbody {
            white-space: -webkit-nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-height: 150px;
            max-width: 260px;
        }
    </style>
</head>

<body>
    <button onclick="calendar.prev();">prev</button>
    <button onclick="calendar.today();">Today</button>
    <button onclick="calendar.next();">next</button>
    <button onclick="switchView();">switch view</button>
    <br />
    <div id="calendar"></div>
    <script>

        function switchView() {
            if (viewState == 'day') {
                viewState = 'week';
            } else {
                if (viewState == 'week') {
                    viewState = 'month';
                } else {
                    viewState = 'day';
                }
            }
            calendar.changeView(viewState, true);
        }

        var viewState = 'day';

        var Calendar = tui.Calendar;
        var calendar = new Calendar('#calendar', {
            usageStatistics: false,
            isReadOnly: true,
            defaultView: viewState,
            month: {
                narrowWeekend: true
            },
            week: {
                narrowWeekend: true,
                showTimezoneCollapseButton: true,
                timezonesCollapsed: false
            },
            timezones: [         // set timezone config
                {
                    timezoneOffset: -300,
                    tooltip: 'Chicago (CDT)',
                    displayLabel: 'Chicago (CDT)'
                },
                {
                    timezoneOffset: -240,
                    tooltip: 'East Coast',
                    displayLabel: 'East Coast'
                },
                {
                    timezoneOffset: 180,
                    tooltip: 'Israel',
                    displayLabel: 'Israel'
                }
            ],
            taskView: false,
            scheduleView: ['time'],
            useDetailPopup: true,
            theme: {
                'week.today.backgroundColor': 'rgba(81, 92, 230, 0.05)'
            },
            template: {
                popupDetailLocation: function (schedule) {
                    return 'Location : <a href="https://synergy2021.mediaspace.kaltura.com/media/' + schedule.location + '" target="_blank">' + schedule.location + '</a>';
                },
                popupDetailBody: function (schedule) {
                    var schedObj = ContainsKeyValue(ScheduleList, 'location', schedule.location);
                    var description = '<div class="popupdescriptionbody">' + truncateWithEllipses(schedule.body, 250) + '</div>';
                    description = 'preStart: ' + (schedObj.preStart / 60) + 'min<br />\n'
                        + 'postEnd: ' + (schedObj.postEnd / 60) + 'min<br />\n'
                        + description;
                    if (schedObj.isSimulive == false) {
                        description = 'Primary: ' + (schedObj.primaryHls != null ? '<a href="' + schedObj.primaryHls + '" target="_blank">m3u8 manifest</a>' : 'N/A') + '<br />\n'
                            + 'Backup: ' + (schedObj.backupHls != null ? '<a href="' + schedObj.backupHls + '" target="_blank">m3u8 manifest</a>' : 'N/A') + '<br />\n'
                            + description;
                    }
                    description
                    return description;
                },
                popupDetailDate: function (isAllDay, start, end) {
                    var westcoastStart = moment.tz(start.getTime(), 'America/Chicago').format('YYYY.MM.DD hh:mm a');
                    var westcoastEnd = moment.tz(end.getTime(), 'America/Chicago').format('hh:mm a');
                    var eastcoastStart = moment.tz(start.getTime(), 'America/New_York').format('YYYY.MM.DD hh:mm a');
                    var eastcoastEnd = moment.tz(end.getTime(), 'America/New_York').format('hh:mm a');
                    var isSameDate = moment(start).isSame(end);
                    var endFormat = (isSameDate ? '' : 'YYYY.MM.DD ') + 'hh:mm a';
                    var timeStr = '<span style="color: darkblue;">Israel</span>: ' + moment.tz(start.getTime(), 'Asia/Jerusalem').format('YYYY.MM.DD hh:mm a') + ' - ' + moment.tz(end.getTime(), 'Asia/Jerusalem').format(endFormat);
                    timeStr = timeStr + '<br /><span style="color: darkblue;">Chicago (CDT)</span>: ' + westcoastStart + ' - ' + westcoastEnd + '\n';
                    timeStr = timeStr + '<br /><span style="color: darkblue;">East Coast</span>: ' + eastcoastStart + ' - ' + eastcoastEnd + '<br />\n';
                    return timeStr;
                },
                milestone: function (schedule) {
                    return '<span style="color:red;"><i class="fa fa-flag"></i> ' + schedule.title + '</span>';
                },
                milestoneTitle: function () {
                    return 'Milestone';
                },
                task: function (schedule) {
                    return '&nbsp;&nbsp;#' + schedule.title;
                },
                taskTitle: function () {
                    return '<label><input type="checkbox" />Task</label>';
                },
                allday: function (schedule) {
                    return schedule.title + ' <i class="fa fa-refresh"></i>';
                },
                alldayTitle: function () {
                    return 'All Day';
                },
                time: function (schedule) {
                    return schedule.title + ' <i class="fa fa-refresh"></i>';
                }
            }
        });

        calendar.createSchedules(ScheduleList);

        calendar.on({
            'clickSchedule': function (e) {
                console.log('clickSchedule', e);
            },
            'beforeCreateSchedule': function (e) {
                console.log('beforeCreateSchedule', e);
                // open a creation popup
            },
            'beforeUpdateSchedule': function (e) {
                console.log('beforeUpdateSchedule', e);
            },
            'beforeDeleteSchedule': function (e) {
                console.log('beforeDeleteSchedule', e);
            },
            'afterRenderSchedule': function (event) {
                //        var schedule = event.schedule;
                //       var element = calendar.getElement(schedule.id, schedule.calendarId);
                //      console.log(element);
            }
        });

        calendar.setDate(moment('2021-05-26', 'YYYY-MM-DD').toDate());

        function truncateWithEllipses(text, max) {
            return text.substr(0, max - 1) + (text.length > max ? '&hellip;' : '');
        }

        function ContainsKeyValue(obj, key, value) {
            if (obj[key] === value) return true;
            for (all in obj) {
                if (obj[all] != null && obj[all][key] === value) {
                    return obj[all];
                }
                if (typeof obj[all] == "object" && obj[all] != null) {
                    var found = ContainsKeyValue(obj[all], key, value);
                    if (found == true) return obj[all];
                }
            }
            return null;
        }
        var urlParams = new URLSearchParams(window.location.search);
        var eid = urlParams.get('eid');
        if (eid != null) {
            eid = eid.trim();
            var scheduleEvent = ContainsKeyValue(ScheduleList, 'location', eid);
            if (scheduleEvent != null) {
                calendar.setDate(new Date(scheduleEvent.start));
            }
        }
    </script>
</body>

</html>

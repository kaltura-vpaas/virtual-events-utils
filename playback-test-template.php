<!DOCTYPE html>
<html>

<head>
    <title>Sample Kaltura Player v7 Simulive Embed</title>
    <link rel="apple-touch-icon" sizes="57x57" href="./kaltura-favicon/apple-icon-57x57.png">
    <link rel="apple-touch-icon" sizes="60x60" href="./kaltura-favicon/apple-icon-60x60.png">
    <link rel="apple-touch-icon" sizes="72x72" href="./kaltura-favicon/apple-icon-72x72.png">
    <link rel="apple-touch-icon" sizes="76x76" href="./kaltura-favicon/apple-icon-76x76.png">
    <link rel="apple-touch-icon" sizes="114x114" href="./kaltura-favicon/apple-icon-114x114.png">
    <link rel="apple-touch-icon" sizes="120x120" href="./kaltura-favicon/apple-icon-120x120.png">
    <link rel="apple-touch-icon" sizes="144x144" href="./kaltura-favicon/apple-icon-144x144.png">
    <link rel="apple-touch-icon" sizes="152x152" href="./kaltura-favicon/apple-icon-152x152.png">
    <link rel="apple-touch-icon" sizes="180x180" href="./kaltura-favicon/apple-icon-180x180.png">
    <link rel="icon" type="image/png" sizes="192x192" href="./kaltura-favicon/android-icon-192x192.png">
    <link rel="icon" type="image/png" sizes="32x32" href="./kaltura-favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="96x96" href="./kaltura-favicon/favicon-96x96.png">
    <link rel="icon" type="image/png" sizes="16x16" href="./kaltura-favicon/favicon-16x16.png">
    <link rel="manifest" href="./kaltura-favicon/manifest.json">
    <meta name="msapplication-TileColor" content="#ffffff">
    <meta name="msapplication-TileImage" content="./kaltura-favicon/ms-icon-144x144.png">
    <meta name="theme-color" content="#ffffff">
    <!-- Responsive Embed CSS -->
    <style>
        body {
            padding: 20px;
        }

        .embed_container {
            position: relative;
            padding-bottom: 56.25%;
            /* 16:9 */
            height: 0;
        }

        .embed_container .kaltura_player_embed {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }

        .refresh-button {
            background-color: #ff0000;
            color: white;
            font-size: large;
            font-weight: bolder;
            margin-top: 1em;
            font-family: 'arial';
            padding: 6px;
        }
    </style>
    <link rel="icon" href="data:,">
    <!-- This line loads the Kaltura player library -->
    <script type="text/javascript" src="<?php echo $kalturaPlayerJavaScriptSrc; ?>"></script>
    <script src="https://kms-a.akamaihd.net/dc-1/latest/public/build0/attendancetracker/asset/plugin/playkit-js-attendance-tracker.js"></script>
</head>

<body>

    <?php echo $simuliveSummary; ?>
    <div>
        <p>Chat moderator tool: <a href="https://synergy2021.mediaspace.kaltura.com/kwebcast/moderator/moderate/entryId/<?php echo $entryId; ?>" target="_blank">click me!</a></p>
    </div>
    <?php echo (isset($assetsBlock) ? $assetsBlock : ''); ?>

    <div style="max-width: 900px;">
        <div class="embed_container">
            <div id="kaltura_player" class="kaltura_player_embed"></div>
        </div>
    </div>

    <button class="refresh-button" title="ONLY works on the test session">Reset the TEST Simulive and Reload!</button>

    <script type="text/javascript">
        try {
            var kalturaPlayer = KalturaPlayer.setup({
                targetId: "kaltura_player",
                provider: {
                    partnerId: <?php echo $partnerId; ?>,
                    uiConfId: <?php echo $uiconfId; ?>,
                    ks: "<?php echo $ks; ?>",
                    env: {
                        cdnUrl: "<?php echo $serviceUrl; ?>",
                        serviceUrl: "<?php echo $serviceUrl; ?>/api_v3"
                    }
                },
                playback: {
                    autoplay: true
                },
                session: {
                    userId: "<?php echo $uniqueUserId; ?>"
                },
                plugins: {
                    "attendance-tracker": {
                        dialogInterval: "20s",
                        randomness: "1s",
                        disable: <?php echo json_encode($disableAtt); ?>
                    }
                }
            });
            kalturaPlayer.loadMedia({
                entryId: "<?php echo $entryId; ?>"
            });
        } catch (e) {
            console.error(e.message)
        }

        const refreshButton = document.querySelector('.refresh-button');

        function refreshPage() {
            kalturaPlayer.destroy();
            var div = document.getElementById('kaltura_player');
            div.innerHTML = '<p>Rescheduling Simulive... please standby...</p>';
            var request = new XMLHttpRequest();
            request.open('GET', './scheduleSimuliveNow.php?env=prod', false); // `false` makes the request synchronous
            request.send(null);
            if (request.status === 200) {
                setTimeout(function() {
                    alert("Rescheduling done. Refreshing the page now!");
                    location.reload();
                }, 3000);
            }
        }

        refreshButton.addEventListener('click', refreshPage)
    </script>
</body>

</html>
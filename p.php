<?php
$channelId = isset($_GET['id']) ? $_GET['id'] : null;
if (!$channelId) {
    die('Channel ID is required. Use: p.php?id=1373');
}
$context = stream_context_create([
    'http' => [
        'timeout' => 10,
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]
]);
$m3uContent = file_get_contents("playlist.m3u", false, $context);
if (!$m3uContent) {
    die('Failed to fetch M3U playlist');
}
$lines = explode("\n", $m3uContent);
$channels = [];
$currentChannel = null;
foreach ($lines as $line) {
    $line = trim($line);
    if (strpos($line, '#EXTINF:') === 0) {
        preg_match('/tvg-id="([^"]*)".*?group-title="([^"]*)".*?tvg-logo="([^"]*)".*?,(.*)$/', $line, $matches);
        $currentChannel = [
            'id' => $matches[1] ?? '',
            'name' => $matches[4] ?? '',
            'group' => $matches[2] ?? '',
            'logo' => $matches[3] ?? '',
            'props' => [],
            'vlc_opts' => [],
            'http' => []
        ];
    } elseif (strpos($line, '#KODIPROP:') === 0 && $currentChannel) {
        $currentChannel['props'][] = substr($line, 10);
    } elseif (strpos($line, '#EXTVLCOPT:') === 0 && $currentChannel) {
        $currentChannel['vlc_opts'][] = substr($line, 11);
    } elseif (strpos($line, '#EXTHTTP:') === 0 && $currentChannel) {
        $currentChannel['http'] = json_decode(substr($line, 9), true) ?: [];
    } elseif (strpos($line, 'http') === 0 && $currentChannel) {
        $currentChannel['url'] = $line;
        $channels[$currentChannel['id']] = $currentChannel;
        $currentChannel = null;
    }
}
if (!isset($channels[$channelId])) {
    die("Channel with ID '$channelId' not found in playlist");
}
$selectedChannel = $channels[$channelId];
$streamUrl = $selectedChannel['url'];
$channelName = $selectedChannel['name'];
$clearkey_id = $clearkey_value = '';
foreach ($selectedChannel['props'] as $prop) {
    if (strpos($prop, 'inputstream.adaptive.license_key=') === 0) {
        $keys = explode(':', substr($prop, 33));
        if (count($keys) === 2) {
            $clearkey_id = $keys[0];
            $clearkey_value = $keys[1];
            break;
        }
    }
}
$userAgent = '';
foreach ($selectedChannel['vlc_opts'] as $opt) {
    if (strpos($opt, 'http-user-agent=') === 0) {
        $userAgent = substr($opt, 16);
        break;
    }
}


$cookie = $selectedChannel['http']['cookie'] ?? '';



$authToken = '';
if ($cookie && preg_match('/__hdnea__=([^;]*)/', $cookie, $matches)) {
    $authToken = $matches[1];
}


$cleanUrl = preg_replace('/[?&](__hdnea__|xxx)=[^&]*/', '', $streamUrl);


?><!DOCTYPE html><html><head>

<script>
setInterval(function() {
  fetch("online.php").catch(()=>{});
}, 15000);
</script>

  <meta charset="UTF-8">
  <title><?php echo htmlspecialchars($channelName); ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/shaka-player/4.6.0/shaka-player.ui.min.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/shaka-player/4.6.0/controls.min.css">
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    html, body {
      background: #000;
      height: 100vh;
      width: 100vw;
      overflow: hidden;
      font-family: system-ui, sans-serif;
    }
    .shaka-video-container {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      background: #000;
    }
    video {
      width: 100%;
      height: 100%;
      background: #000;
      object-fit: contain;
    }
    @media (orientation: landscape) {
      video {
        object-fit: fill !important;
      }
    }
    .shaka-spinner { display: none !important; }
    .watermark {
      position: absolute;
      bottom: 24px;
      right: 45px;
      font-size: 20px;
      color: white;
      opacity: 0.3;
      z-index: 10;
      pointer-events: none;
      animation: shine 4.4s infinite;
      background: linear-gradient(120deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0.8) 50%, rgba(255,255,255,0.1) 100%);
      background-size: 200% auto;
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }
    @keyframes shine {
      0% {
        background-position: 200% center;
      }
      100% {
        background-position: -200% center;
      }
    }
    #fullscreen-btn {
      position: absolute;
      top: 10px;
      right: 10px;
      background: rgba(0,0,0,0.6);
      color: white;
      border: none;
      padding: 8px 12px;
      font-size: 14px;
      border-radius: 6px;
      z-index: 100;
      display: none;
    }
    #brightness-overlay {
      position: absolute;
      inset: 0;
      background: black;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.2s;
      z-index: 9;
    }
    #gesture-feedback {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      background: rgba(0,0,0,0.6);
      color: white;
      padding: 10px 20px;
      font-size: 16px;
      border-radius: 8px;
      z-index: 999;
      display: none;
      pointer-events: none;
    }
  </style>
</head>
<body>
<div class="shaka-video-container" data-shaka-player>
  <video autoplay unmuted playsinline preload="metadata"></video>
  <div class="watermark">Muskan</div>
  <button id="fullscreen-btn">⛶ Fullscreen</button>
  <div id="brightness-overlay"></div>
  <div id="gesture-feedback"></div>
</div>
<script>













document.addEventListener('DOMContentLoaded', async () => {
  shaka.polyfill.installAll();
  if (!shaka.Player.isBrowserSupported()) {
    console.error('Browser not supported');
    return;
  }
  const video = document.querySelector('video');
  const player = new shaka.Player(video);
  const container = document.querySelector('.shaka-video-container');
  const ui = new shaka.ui.Overlay(player, container, video);
  ui.getControls();
  player.configure({
    <?php if ($clearkey_id && $clearkey_value): ?>
    drm: { clearKeys: { "<?php echo $clearkey_id; ?>": "<?php echo $clearkey_value; ?>" } },
    <?php endif; ?>
    streaming: {
      bufferingGoal: 30,
      rebufferingGoal: 5,
      bufferBehind: 30,
      segmentRequestTimeout: 10000
    }
  });
  player.getNetworkingEngine().registerRequestFilter((type, request) => {
    request.headers['Referer'] = 'https://www.jiotv.com/';
    <?php if ($userAgent): ?>
    request.headers['User-Agent'] = "<?php echo addslashes($userAgent); ?>";
    <?php endif; ?>
    <?php if ($cookie): ?>
    request.headers['Cookie'] = "<?php echo addslashes($cookie); ?>";
    <?php endif; ?>
    <?php if ($authToken): ?>
    if ((type === shaka.net.NetworkingEngine.RequestType.MANIFEST || type === shaka.net.NetworkingEngine.RequestType.SEGMENT) && request.uris[0].indexOf('__hdnea__=') === -1) {
      const sep = request.uris[0].includes('?') ? '&' : '?';
      request.uris[0] += sep + '__hdnea__=<?php echo addslashes($authToken); ?>';
    }
    <?php endif; ?>
  });
  player.addEventListener('error', e => console.error('Shaka Error:', e.detail));
  try {
   await player.load("<?php echo addslashes($cleanUrl); ?>");
  } catch (e) {
    console.error('Load error:', e);
  }
  const fsBtn = document.getElementById('fullscreen-btn');
  let fsTimeout;
  container.addEventListener('click', () => {
    fsBtn.style.display = 'block';
    clearTimeout(fsTimeout);
    fsTimeout = setTimeout(() => fsBtn.style.display = 'none', 5000);
  });
  fsBtn.addEventListener('click', async () => {
    if (container.requestFullscreen) await container.requestFullscreen();
    else if (container.webkitRequestFullscreen) await container.webkitRequestFullscreen();
    if (screen.orientation && screen.orientation.lock) {
      try {
        await screen.orientation.lock('landscape');
      } catch (e) {
        console.warn('Orientation lock failed:', e);
      }
    }
  });
  video.addEventListener('dblclick', () => {
    if (document.pictureInPictureEnabled && !video.disablePictureInPicture) {
      video.requestPictureInPicture().catch(() => {});
    }
  });
  let startX = 0, startY = 0;
  let brightness = 0;
  let volume = video.volume;
  const brightnessOverlay = document.getElementById('brightness-overlay');
  const gestureBox = document.getElementById('gesture-feedback');
  function showGestureFeedback(type, value) {
    gestureBox.innerText = `${type}: ${Math.round(value * 100)}%`;
    gestureBox.style.display = 'block';
    clearTimeout(gestureBox._timeout);
    gestureBox._timeout = setTimeout(() => {
      gestureBox.style.display = 'none';
    }, 1000);
  }
  container.addEventListener('touchstart', e => {
    startX = e.touches[0].clientX;
    startY = e.touches[0].clientY;
  });
  container.addEventListener('touchmove', e => {
    const dx = e.touches[0].clientX - startX;
    const dy = e.touches[0].clientY - startY;
    const percent = -dy / window.innerHeight;
    if (startX > window.innerWidth / 2) {
      volume = Math.min(1, Math.max(0, volume + percent));
      video.volume = volume;
      showGestureFeedback('Volume', volume);
    } else {
      brightness = Math.min(0.7, Math.max(0, brightness + percent));
      brightnessOverlay.style.opacity = brightness.toFixed(2);
      showGestureFeedback('Brightness', brightness / 0.7);
    }
    startY = e.touches[0].clientY;
  });
});
</script>
<?php include("track.php"); ?>
</body>
</html>

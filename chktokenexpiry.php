<?php
// include('master.php');
$CASSIE = "";
$tt = "1373";

$KANG = 'stream_type=Seek&channel_id=' . $tt;

    $ROLEX = [
        'User-Agent: ' . $SCARLET_WITCH['User-Agent'],
        'Accept-Encoding: gzip',
        'Content-Type: application/x-www-form-urlencoded',
        'appkey: NzNiMDhlYzQyNjJm',
        'devicetype: ' . $SCARLET_WITCH['deviceType'],
        'os: ' . $SCARLET_WITCH['os'],
        'deviceId: ' . $BLOODY_SWEET['deviceId'],
        'versionCode: ' . $SCARLET_WITCH['versionCode'],
        'osversion: ' . $SCARLET_WITCH['osversion'],
        'dm: ' . $SCARLET_WITCH['model'],
        'uniqueid: ' . $BLOODY_SWEET['sessionAttributes']['user']['unique'],
        'usergroup: tvYR7NSNn7rymo3F',
        'languageid: 6',
        'userid: ' . $BLOODY_SWEET['sessionAttributes']['user']['uid'],
        'sid: ' . $BLOODY_SWEET['analyticsId'],
        'crmid: ' . $BLOODY_SWEET['sessionAttributes']['user']['subscriberId'],
        'isott: false',
        'channel_id: ' . $_REQUEST['id'],
        'langid: ',
        'camid: ',
        'accesstoken: ' . jio_tv_re_use_refreshtoken_generate(),
        'ssotoken: ' . $BLOODY_SWEET['jToken'],
        'subscriberid: ' . $BLOODY_SWEET['sessionAttributes']['user']['subscriberId'],
        'lbcookie: 1',
    ];
    $DOCTOR_STRANGE = jitendraunatti(base64_decode(hex2bin($TONY_STARK["geturl"])), $ROLEX, 'POST', $KANG, 0, 0, 0, 0, 0, 0, 0, 0);
    $CHRISTINE =  json_decode($DOCTOR_STRANGE['JITENDRAUNATTI']['data'], true);

    if ($CHRISTINE['code'] == 419 && $CHRISTINE['message'] === 'Token is expired') {
    jio_tv_refreshtoken_generate();
}



    //    header('Location: ' . $_SERVER['REQUEST_URI']);
     //   exit();
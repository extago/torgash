<!doctype html>
<html>
<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Page cache test</title>
</head>
<body>
    <form method="POST" action="/pc-test/post">@csrf</form>
    <script nonce="{{ csp_nonce() }}">window.pcTest = 1;</script>
    <div id="secret">@personal('pctest::secret', [], true)</div>
    <div id="note">@personal('pctest::note', ['n' => 7])</div>
    <div id="copies">@personal('pctest::copies', [], true)</div>
    <div id="body">{{ $body ?? 'plain' }}</div>
</body>
</html>

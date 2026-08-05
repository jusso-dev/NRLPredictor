<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API Reference — NRL Try Predictor</title>
    <style>
        body { margin: 0; background: #0A0A0A; color: #E0E2E7; font-family: ui-sans-serif, system-ui, sans-serif; }
        header { background: #000; border-bottom: 1px solid rgba(255,255,255,.05); padding: 14px 20px; display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }
        .logo { width: 28px; height: 28px; border-radius: 2px; background: #00B852; color: #000; font-weight: 700; display: grid; place-items: center; }
        h1 { font-size: 16px; margin: 0; text-transform: uppercase; letter-spacing: .02em; }
        a.dl { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: #E0E2E7; text-decoration: none; border: 1px solid #3A3A3A; border-radius: 4px; padding: 7px 11px; }
        a.dl:hover { border-color: #00B852; color: #1FD46B; }
        .spacer { flex: 1 1 auto; }
        #fallback { padding: 24px 20px; font-size: 14px; line-height: 1.6; }
        #fallback code { background: #1E1E1E; border: 1px solid #3A3A3A; border-radius: 4px; padding: 2px 6px; font-size: 13px; }
        redoc { display: block; background: #fff; }
    </style>
</head>
<body>
    <header>
        <div class="logo">N</div>
        <h1>NRL Try Predictor — API reference</h1>
        <div class="spacer"></div>
        <a class="dl" href="{{ url('/api/openapi.yaml') }}?download=1">Download YAML</a>
        <a class="dl" href="{{ url('/api/openapi.json') }}?download=1">Download JSON</a>
    </header>

    <div id="fallback">
        Loading the interactive reference… If it does not appear, this host has no internet access for the
        renderer — fetch the document directly instead:
        <code>curl -O {{ url('/api/openapi.yaml') }}?download=1</code>
    </div>

    <redoc spec-url="{{ url('/api/openapi.json') }}" hide-download-button></redoc>
    <script
        src="https://cdn.redoc.ly/redoc/latest/bundles/redoc.standalone.js"
        onload="document.getElementById('fallback').remove()"
    ></script>
</body>
</html>

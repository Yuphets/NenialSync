<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#0d3e28">
    <title>Nenial • Currently under maintenance</title>
    <style>
        :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; color: #17231e; background: radial-gradient(circle at top right, #dff1e6, #f4f7f5 48%, #e8efe9); }
        main { display: grid; place-items: center; min-height: 100vh; padding: 24px; }
        article { width: min(620px, 100%); padding: clamp(28px, 6vw, 54px); border: 1px solid #cfddd5; border-radius: 24px; background: rgba(255, 255, 255, .96); box-shadow: 0 30px 80px rgba(13, 62, 40, .15); text-align: center; }
        img { width: 76px; height: 76px; border-radius: 18px; object-fit: cover; box-shadow: 0 10px 28px rgba(13, 62, 40, .2); }
        .eyebrow { display: block; margin-top: 24px; color: #176b43; font-size: .75rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
        h1 { margin: 12px 0; color: #0d3e28; font-size: clamp(2rem, 7vw, 3.4rem); line-height: 1.02; letter-spacing: -.05em; }
        p { max-width: 480px; margin: 0 auto; color: #5e6e65; font-size: 1.02rem; line-height: 1.65; }
        .status { display: inline-flex; align-items: center; gap: 8px; margin: 26px 0 20px; padding: 9px 13px; border-radius: 999px; color: #725000; background: #fff1c9; font-size: .78rem; font-weight: 750; }
        .status::before { width: 8px; height: 8px; border-radius: 50%; background: #d78a2f; content: ""; }
        a { display: inline-flex; min-height: 44px; align-items: center; justify-content: center; margin-top: 8px; padding: 0 18px; border: 1px solid #cad8d0; border-radius: 10px; color: #0d3e28; background: #fff; font-weight: 750; text-decoration: none; }
        a:hover { border-color: #176b43; box-shadow: 0 8px 22px rgba(13, 62, 40, .1); }
        small { display: block; margin-top: 22px; color: #829087; }
    </style>
</head>
<body>
<main>
    <article>
        <img src="/media/Nenial.jpg" alt="Nenial">
        <span class="eyebrow">Nenial Enterprises</span>
        <h1>Currently under maintenance</h1>
        <p>{{ $maintenance['message'] }}</p>
        <div class="status">Service temporarily unavailable</div>
        <div><a href="/login?maintenance=1">Log in</a></div>
        <small>Store services will return as soon as maintenance is complete.</small>
    </article>
</main>
</body>
</html>

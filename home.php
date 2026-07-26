<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EMS Home</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            line-height: 1.6;
            color: #333;
            background: #f4f6f8;
        }

        .card {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        .carousel {
            position: relative;
            width: 100%;
            height: 420px;
            overflow: hidden;
            border-radius: 12px;
            background: #000;
            margin-bottom: 1.5rem;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.16);
        }

        .carousel img {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            opacity: 0;
            transition: opacity 1s ease-in-out;
        }

        .carousel img.active {
            opacity: 1;
        }

        .carousel .caption {
            position: absolute;
            left: 1.5rem;
            bottom: 1.5rem;
            background: rgba(0, 0, 0, 0.65);
            color: #fff;
            padding: 0.8rem 1rem;
            border-radius: 8px;
            font-weight: bold;
        }

        .content {
            background: #fff;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        a {
            color: #0b5fff;
        }
    </style>
</head>
<body>
    <div class="card">
        <?php
        $sliderDir = __DIR__ . '/images/slider';
        $sliderImages = [];

        if (is_dir($sliderDir)) {
            $files = scandir($sliderDir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $fullPath = $sliderDir . '/' . $file;
                if (is_file($fullPath)) {
                    $sliderImages[] = '/images/slider/' . $file;
                }
            }
        }
        ?>

        <?php if (!empty($sliderImages)): ?>
            <div class="carousel" id="heroCarousel">
                <?php for ($i = 0; $i < count($sliderImages); $i++): ?>
                    <img src="<?php echo htmlspecialchars($sliderImages[$i], ENT_QUOTES, 'UTF-8'); ?>" alt="Event slider image <?php echo $i + 1; ?>" class="<?php echo $i === 0 ? 'active' : ''; ?>">
                <?php endfor; ?>
                <div class="caption">Featured Events</div>
            </div>
        <?php endif; ?>

        <div class="content">
            <p><a href="/register">Register for Event</a></p>
        </div>
    </div>

    <script>
        (function () {
            const carousel = document.getElementById('heroCarousel');
            if (!carousel) {
                return;
            }

            const slides = carousel.querySelectorAll('img');
            let current = 0;

            if (slides.length <= 1) {
                return;
            }

            setInterval(function () {
                slides[current].classList.remove('active');
                current = (current + 1) % slides.length;
                slides[current].classList.add('active');
            }, 3000);
        })();
    </script>
</body>
</html>

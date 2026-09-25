<?php
declare(strict_types=1);

/**
 * Standalone Android APK landing page (not part of the signed-in web app).
 * Share: https://ccspulseconnect.com/get_app
 */

$apkUrl = 'https://www.mediafire.com/file/zeuia2r2qbexy4l/PulseCONNECT%2528v2%2529.apk/file';
$appName = 'PulseCONNECT';
$pageTitle = 'Download PulseCONNECT · Android APK';
$version = 'v2.0.0';
$fileSize = '75.53 MB';
$updatedDate = 'September 2026';
$developers = 'MARK STEPHEN ESPINOSA / CIAN CLYDE TANDANG';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#3b0a0a">
  <meta name="description" content="Download PulseCONNECT Android APK. Events, digital tickets, offline attendance scanner, and student credentials for College of Computing Studies at Laguna University.">
  <meta name="robots" content="noindex, nofollow">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="icon" type="image/png" href="/assets/favicon-32.png">
  <link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    :root {
      --maroon-deep: #190303;
      --maroon-dark: #2a0505;
      --maroon-base: #520f0f;
      --maroon-vibrant: #7f1d1d;
      --crimson-light: #b91c1c;
      --gold-light: #fef08a;
      --gold: #f59e0b;
      --gold-dark: #d97706;
      --white: #ffffff;
      --white-90: rgba(255, 255, 255, 0.90);
      --white-75: rgba(255, 255, 255, 0.75);
      --white-50: rgba(255, 255, 255, 0.50);
      --white-15: rgba(255, 255, 255, 0.15);
      --white-08: rgba(255, 255, 255, 0.08);
      --glass-bg: rgba(255, 255, 255, 0.05);
      --glass-border: rgba(255, 255, 255, 0.12);
      --card-hover: rgba(255, 255, 255, 0.08);
      --radius-lg: 24px;
      --radius-md: 16px;
      --radius-sm: 12px;
      --shadow-soft: 0 20px 40px -15px rgba(0, 0, 0, 0.5);
      --shadow-glow: 0 0 50px rgba(185, 28, 28, 0.35);
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    html { scroll-behavior: smooth; }
    body {
      font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
      color: var(--white);
      background-color: var(--maroon-deep);
      background-image:
        radial-gradient(ellipse 90% 60% at 50% -20%, rgba(136, 25, 25, 0.65), transparent 70%),
        radial-gradient(ellipse 60% 40% at 85% 30%, rgba(245, 158, 11, 0.12), transparent 60%),
        radial-gradient(ellipse 70% 50% at 10% 70%, rgba(185, 28, 28, 0.3), transparent 70%),
        radial-gradient(circle at 50% 100%, rgba(45, 6, 6, 0.8), #120202);
      background-attachment: fixed;
      min-height: 100vh;
      overflow-x: hidden;
      line-height: 1.5;
    }

    /* Ambient background mesh decoration */
    .bg-glow-orb {
      position: fixed;
      border-radius: 50%;
      filter: blur(100px);
      pointer-events: none;
      z-index: 0;
      opacity: 0.45;
    }
    .bg-glow-1 {
      top: 5%;
      left: 20%;
      width: 450px;
      height: 450px;
      background: radial-gradient(circle, #b91c1c 0%, transparent 70%);
      animation: pulseGlow 10s ease-in-out infinite alternate;
    }
    .bg-glow-2 {
      top: 45%;
      right: 5%;
      width: 400px;
      height: 400px;
      background: radial-gradient(circle, rgba(245, 158, 11, 0.4) 0%, transparent 70%);
      animation: pulseGlow 12s ease-in-out infinite alternate-reverse;
    }

    @keyframes pulseGlow {
      0% { transform: scale(0.9) translate(0, 0); opacity: 0.3; }
      100% { transform: scale(1.15) translate(20px, 30px); opacity: 0.55; }
    }

    /* Layout Container */
    .main-container {
      position: relative;
      z-index: 1;
      max-width: 1240px;
      margin: 0 auto;
      padding: 24px 20px 80px;
    }

    /* Top Navigation / Brand Header */
    .nav-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 12px 20px;
      background: rgba(45, 6, 6, 0.65);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid var(--glass-border);
      border-radius: 999px;
      margin-bottom: 36px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
    }
    .brand-link {
      display: flex;
      align-items: center;
      gap: 12px;
      text-decoration: none;
      color: inherit;
    }
    .brand-logo {
      width: 38px;
      height: 38px;
      border-radius: 10px;
      object-fit: contain;
      background: #ffffff;
      padding: 2px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    }
    .brand-text {
      display: flex;
      flex-direction: column;
    }
    .brand-name {
      font-size: 15px;
      font-weight: 800;
      letter-spacing: -0.01em;
      color: var(--white);
    }
    .brand-sub {
      font-size: 11px;
      font-weight: 600;
      color: var(--gold);
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    .nav-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 14px;
      font-size: 12px;
      font-weight: 700;
      color: #fef08a;
      background: rgba(245, 158, 11, 0.15);
      border: 1px solid rgba(245, 158, 11, 0.35);
      border-radius: 999px;
    }
    .nav-badge-dot {
      width: 7px;
      height: 7px;
      background: #22c55e;
      border-radius: 50%;
      box-shadow: 0 0 8px #22c55e;
      animation: pulseGreen 2s infinite;
    }
    @keyframes pulseGreen {
      0%, 100% { opacity: 1; transform: scale(1); }
      50% { opacity: 0.5; transform: scale(0.85); }
    }

    /* Play Store Hero Section */
    .playstore-hero {
      display: grid;
      grid-template-columns: auto 1fr;
      gap: 32px;
      align-items: center;
      background: linear-gradient(145deg, rgba(255, 255, 255, 0.07) 0%, rgba(255, 255, 255, 0.02) 100%);
      backdrop-filter: blur(24px);
      -webkit-backdrop-filter: blur(24px);
      border: 1px solid var(--glass-border);
      border-radius: var(--radius-lg);
      padding: 40px;
      box-shadow: var(--shadow-soft), var(--shadow-glow);
      position: relative;
      overflow: hidden;
      margin-bottom: 50px;
    }
    .playstore-hero::before {
      content: "";
      position: absolute;
      top: -80px;
      right: -80px;
      width: 260px;
      height: 260px;
      background: radial-gradient(circle, rgba(245, 158, 11, 0.25), transparent 70%);
      pointer-events: none;
    }

    /* Hero Logo & Collision Animation (Web Login Style) */
    .hero-icon-col {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      width: 280px;
      min-width: 260px;
      flex-shrink: 0;
    }

    .logo-collision-wrapper {
      position: relative;
      width: 100%;
      max-width: 280px;
      height: 180px;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: visible;
    }

    .logo-bsit, .logo-cs {
      position: absolute;
      width: 82px;
      height: 82px;
      object-fit: contain;
      filter: drop-shadow(0 12px 20px rgba(0, 0, 0, 0.6));
      animation-duration: 9s;
      animation-iteration-count: infinite;
      animation-timing-function: cubic-bezier(0.34, 1.56, 0.64, 1);
      z-index: 2;
    }

    .logo-bsit {
      left: 10%;
      animation-name: collideLeft;
    }

    .logo-cs {
      right: 10%;
      animation-name: collideRight;
    }

    .collision-flash {
      position: absolute;
      width: 10px;
      height: 10px;
      background: #fef3c7;
      border-radius: 50%;
      box-shadow: 0 0 80px 60px rgba(253, 230, 138, 0.9),
                  0 0 150px 100px rgba(245, 158, 11, 0.7),
                  0 0 200px 150px rgba(217, 119, 6, 0.5);
      opacity: 0;
      animation: flashBurst 9s infinite ease-out;
      z-index: 1;
    }

    .logo-ccs {
      position: absolute;
      inset: 0;
      margin: auto;
      width: 155px;
      height: 155px;
      object-fit: contain;
      opacity: 0;
      animation: revealCCS 9s infinite ease-out;
      z-index: 3;
      filter: drop-shadow(0 15px 30px rgba(245, 158, 11, 0.45));
    }

    @keyframes collideLeft {
      0%, 5% { transform: translateX(0) scale(0.8) rotate(-15deg); opacity: 0; filter: drop-shadow(0 0 20px rgba(159, 18, 57, 0.5)); }
      10% { opacity: 1; }
      22% { transform: translateX(110%) scale(1.1) rotate(10deg); filter: drop-shadow(0 0 30px rgba(159, 18, 57, 0.8)); }
      25%, 100% { transform: translateX(110%) scale(1.3) rotate(15deg); opacity: 0; }
    }

    @keyframes collideRight {
      0%, 5% { transform: translateX(0) scale(0.8) rotate(15deg); opacity: 0; filter: drop-shadow(0 0 20px rgba(22, 163, 74, 0.5)); }
      10% { opacity: 1; }
      22% { transform: translateX(-110%) scale(1.1) rotate(-10deg); filter: drop-shadow(0 0 30px rgba(22, 163, 74, 0.8)); }
      25%, 100% { transform: translateX(-110%) scale(1.3) rotate(-15deg); opacity: 0; }
    }

    @keyframes flashBurst {
      0%, 21% { opacity: 0; transform: scale(0); }
      22% { opacity: 1; transform: scale(1.5); }
      28% { opacity: 0.8; transform: scale(3); }
      45% { opacity: 0; transform: scale(4); }
      100% { opacity: 0; }
    }

    @keyframes revealCCS {
      0%, 22% { opacity: 0; transform: scale(0.5) translateY(20px); filter: blur(10px); }
      25% { opacity: 1; transform: scale(1.1) translateY(0); filter: blur(0); }
      28%, 85% { opacity: 1; transform: scale(1) translateY(0); filter: blur(0); } 
      92%, 100% { opacity: 0; transform: scale(0.9) translateY(-20px); filter: blur(5px); }
    }

    .lightning-strike {
      position: absolute;
      top: 10%;
      left: 50%;
      width: 15px;
      height: 120px;
      background: #fff;
      opacity: 0;
      box-shadow: 0 0 20px #fff, 0 0 40px #fde047, 0 0 80px #facc15;
      filter: blur(1px) drop-shadow(0 0 15px #fef08a);
      clip-path: polygon(30% 0, 100% 0, 60% 40%, 100% 40%, 10% 100%, 40% 60%, 0% 60%);
      z-index: 5;
      animation: lightningBolt 9s infinite linear;
      transform-origin: top center;
    }

    .spark {
      position: absolute;
      width: 3px;
      height: 40px;
      background: #fff;
      border-radius: 4px;
      box-shadow: 0 0 10px #fff, 0 0 20px #fde047, 0 0 40px #eab308;
      opacity: 0;
      z-index: 4;
    }

    .spark-1 { top: 50%; left: 50%; animation: spark1 9s infinite cubic-bezier(0.1, 1, 0.3, 1); }
    .spark-2 { top: 50%; left: 50%; animation: spark2 9s infinite cubic-bezier(0.1, 1, 0.3, 1); }
    .spark-3 { top: 50%; left: 50%; animation: spark3 9s infinite cubic-bezier(0.1, 1, 0.3, 1); }
    .spark-4 { top: 50%; left: 50%; animation: spark4 9s infinite cubic-bezier(0.1, 1, 0.3, 1); }

    @keyframes spark1 {
      0%, 21% { opacity: 0; transform: translate(-50%, -50%) rotate(45deg) scaleY(0); }
      22% { opacity: 1; transform: translate(60px, -60px) rotate(45deg) scaleY(2); }
      25%, 100% { opacity: 0; transform: translate(120px, -120px) rotate(45deg) scaleY(0); }
    }
    @keyframes spark2 {
      0%, 21% { opacity: 0; transform: translate(-50%, -50%) rotate(-45deg) scaleY(0); }
      22% { opacity: 1; transform: translate(-60px, -60px) rotate(-45deg) scaleY(2); }
      25%, 100% { opacity: 0; transform: translate(-120px, -120px) rotate(-45deg) scaleY(0); }
    }
    @keyframes spark3 {
      0%, 21% { opacity: 0; transform: translate(-50%, -50%) rotate(135deg) scaleY(0); }
      22% { opacity: 1; transform: translate(60px, 60px) rotate(135deg) scaleY(1.5); }
      25%, 100% { opacity: 0; transform: translate(100px, 100px) rotate(135deg) scaleY(0); }
    }
    @keyframes spark4 {
      0%, 21% { opacity: 0; transform: translate(-50%, -50%) rotate(-135deg) scaleY(0); }
      22% { opacity: 1; transform: translate(-60px, 60px) rotate(-135deg) scaleY(1.5); }
      25%, 100% { opacity: 0; transform: translate(-100px, 100px) rotate(-135deg) scaleY(0); }
    }

    @keyframes lightningBolt {
      0%, 21.8% { opacity: 0; transform: translateX(-50%) scaleY(0); }
      22% { opacity: 1; transform: translateX(-50%) scaleY(1.2); filter: blur(0px) brightness(1.5); }
      23% { opacity: 0.2; transform: translateX(-50%) scaleY(1); }
      23.5% { opacity: 0.8; transform: translateX(-50%) scaleY(1.1) scaleX(-1); }
      24.5% { opacity: 0; transform: translateX(-50%) scaleY(1); }
      100% { opacity: 0; }
    }

    /* Hero Info */
    .hero-info-col {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }
    .hero-pretitle {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }
    .tag-category {
      font-size: 12px;
      font-weight: 800;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--gold);
      background: rgba(245, 158, 11, 0.15);
      padding: 4px 10px;
      border-radius: 8px;
    }
    .tag-inst {
      font-size: 13px;
      font-weight: 600;
      color: var(--white-75);
    }
    .hero-title {
      font-size: clamp(2.2rem, 5vw, 3.4rem);
      font-weight: 900;
      letter-spacing: -0.03em;
      line-height: 1.08;
      display: inline-block;
      max-width: 100%;
      background-image: linear-gradient(
        90deg,
        #ffffff 0%,
        #e2e8f0 32%,
        #fef08a 46%,
        #ffffff 50%,
        #fef08a 54%,
        #e2e8f0 68%,
        #ffffff 100%
      );
      background-size: 240% 100%;
      background-position: 100% 0;
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
      -webkit-text-fill-color: transparent;
      animation: pcShineSweep 3s linear infinite;
      filter: drop-shadow(0 2px 10px rgba(0, 0, 0, 0.4));
    }

    @keyframes pcShineSweep {
      0% {
        background-position: 100% 0;
      }
      100% {
        background-position: -100% 0;
      }
    }
    .hero-dev {
      font-size: 15px;
      font-weight: 600;
      color: var(--white-75);
      margin-top: -6px;
    }
    .hero-desc {
      font-size: 15.5px;
      line-height: 1.6;
      color: var(--white-90);
      max-width: 680px;
    }

    /* Google Play Style Stats Strip */
    .play-metrics-strip {
      display: flex;
      align-items: center;
      gap: 18px;
      padding: 16px 20px;
      background: rgba(0, 0, 0, 0.25);
      border: 1px solid var(--glass-border);
      border-radius: var(--radius-md);
      margin: 6px 0 10px;
      overflow-x: auto;
      scrollbar-width: none;
    }
    .play-metrics-strip::-webkit-scrollbar { display: none; }
    .metric-item {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      min-width: 85px;
      text-align: center;
      position: relative;
    }
    .metric-item:not(:last-child)::after {
      content: "";
      position: absolute;
      right: -9px;
      top: 15%;
      height: 70%;
      width: 1px;
      background: rgba(255, 255, 255, 0.12);
    }
    .metric-val {
      font-size: 16px;
      font-weight: 800;
      color: var(--white);
      display: flex;
      align-items: center;
      gap: 4px;
    }
    .metric-val svg.star {
      color: #f59e0b;
    }
    .metric-lbl {
      font-size: 11px;
      font-weight: 600;
      color: var(--white-50);
      margin-top: 2px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    /* Hero CTA Actions */
    .hero-actions {
      display: flex;
      align-items: center;
      gap: 16px;
      flex-wrap: wrap;
      margin-top: 6px;
    }
    .btn-download-primary {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 12px;
      padding: 16px 36px;
      border-radius: 999px;
      background: linear-gradient(135deg, #dc2626 0%, #b91c1c 45%, #991b1b 100%);
      color: var(--white);
      font-size: 17px;
      font-weight: 800;
      text-decoration: none;
      box-shadow: 0 14px 32px rgba(185, 28, 28, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.2) inset;
      transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
      position: relative;
      overflow: hidden;
    }
    .btn-download-primary::after {
      content: "";
      position: absolute;
      top: 0;
      left: -100%;
      width: 60%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.25), transparent);
      transform: skewX(-20deg);
      transition: 0.75s;
    }
    .btn-download-primary:hover {
      transform: translateY(-2px) scale(1.02);
      box-shadow: 0 20px 40px rgba(185, 28, 28, 0.65), 0 0 0 1px rgba(255, 255, 255, 0.35) inset;
    }
    .btn-download-primary:hover::after {
      left: 140%;
    }
    .btn-download-primary:active {
      transform: translateY(1px);
    }
    .btn-secondary {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 15px 26px;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.08);
      border: 1px solid var(--glass-border);
      color: var(--white);
      font-size: 15px;
      font-weight: 700;
      text-decoration: none;
      transition: all 0.2s ease;
    }
    .btn-secondary:hover {
      background: rgba(255, 255, 255, 0.15);
      border-color: rgba(255, 255, 255, 0.25);
      transform: translateY(-1px);
    }
    .btn-secondary:active {
      transform: translateY(1px);
    }
    .download-subtext {
      font-size: 12.5px;
      color: var(--white-50);
      margin-top: -6px;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .update-install-note {
      display: flex;
      align-items: flex-start;
      gap: 12px;
      max-width: 650px;
      margin-top: 18px;
      padding: 13px 16px;
      border: 1px solid rgba(251, 191, 36, 0.72);
      border-radius: 14px;
      background: linear-gradient(135deg, rgba(251, 191, 36, 0.2), rgba(220, 38, 38, 0.16));
      box-shadow: 0 10px 24px rgba(0, 0, 0, 0.18);
    }
    .update-install-note-icon {
      flex: 0 0 auto;
      width: 24px;
      height: 24px;
      padding: 5px;
      border-radius: 50%;
      background: #fbbf24;
      color: #451a03;
    }
    .update-install-note-copy {
      color: rgba(255, 255, 255, 0.88);
      font-size: 13px;
      line-height: 1.45;
    }
    .update-install-note-copy strong {
      display: block;
      margin-bottom: 2px;
      color: #fef3c7;
      font-size: 13.5px;
      letter-spacing: 0.01em;
    }

    /* SECTION 2: PLAY STORE APP SCREENSHOTS SHOWCASE (HIGHLIGHT) */
    .section-showcase {
      margin-bottom: 60px;
    }
    .section-header {
      display: flex;
      align-items: flex-end;
      justify-content: space-between;
      margin-bottom: 24px;
      flex-wrap: wrap;
      gap: 16px;
    }
    .section-title-wrap h2 {
      font-size: clamp(1.6rem, 3.2vw, 2.2rem);
      font-weight: 800;
      letter-spacing: -0.02em;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .section-title-wrap p {
      font-size: 14.5px;
      color: var(--white-75);
    }

    /* Horizontal Carousel Track */
    .carousel-container {
      position: relative;
      margin: 0 -12px;
      padding: 10px 12px 20px;
    }
    .carousel-track {
      display: flex;
      gap: 22px;
      overflow-x: auto;
      scroll-snap-type: x mandatory;
      scroll-behavior: smooth;
      padding-bottom: 15px;
      scrollbar-width: thin;
      scrollbar-color: rgba(245, 158, 11, 0.4) transparent;
      -webkit-overflow-scrolling: touch;
      cursor: grab;
      user-select: none;
    }
    .carousel-track:active {
      cursor: grabbing;
    }
    .carousel-track::-webkit-scrollbar {
      height: 8px;
    }
    .carousel-track::-webkit-scrollbar-track {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 999px;
    }
    .carousel-track::-webkit-scrollbar-thumb {
      background: linear-gradient(90deg, #b91c1c, #f59e0b);
      border-radius: 999px;
    }

    /* Individual Showcase Card */
    .showcase-card {
      flex: 0 0 280px;
      scroll-snap-align: start;
      position: relative;
      border-radius: 22px;
      background: linear-gradient(180deg, rgba(255, 255, 255, 0.08) 0%, rgba(20, 3, 3, 0.7) 100%);
      border: 1px solid var(--glass-border);
      padding: 10px;
      box-shadow: 0 16px 36px rgba(0, 0, 0, 0.4);
      transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
      cursor: pointer;
      overflow: hidden;
      text-decoration: none;
      color: inherit;
    }
    @media (min-width: 768px) {
      .showcase-card {
        flex: 0 0 295px;
      }
    }
    .showcase-card:hover {
      transform: translateY(-8px) scale(1.02);
      border-color: rgba(245, 158, 11, 0.5);
      box-shadow: 0 24px 50px rgba(0, 0, 0, 0.6), 0 0 30px rgba(245, 158, 11, 0.25);
    }
    .showcase-img-wrap {
      width: 100%;
      aspect-ratio: 9 / 18.5;
      border-radius: 16px;
      overflow: hidden;
      position: relative;
      background: #250505;
    }
    .showcase-img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
      transition: transform 0.5s ease;
    }
    .showcase-card:hover .showcase-img {
      transform: scale(1.04);
    }
    .showcase-zoom-hint {
      position: absolute;
      top: 14px;
      right: 14px;
      background: rgba(0, 0, 0, 0.65);
      backdrop-filter: blur(8px);
      width: 36px;
      height: 36px;
      border-radius: 50%;
      display: grid;
      place-items: center;
      color: #fff;
      opacity: 0;
      transform: scale(0.8);
      transition: all 0.25s ease;
      border: 1px solid rgba(255, 255, 255, 0.2);
    }
    .showcase-card:hover .showcase-zoom-hint {
      opacity: 1;
      transform: scale(1);
    }
    .showcase-caption {
      padding: 14px 6px 6px;
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .showcase-badge {
      font-size: 11px;
      font-weight: 800;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--gold);
    }
    .showcase-title {
      font-size: 16px;
      font-weight: 800;
      color: var(--white);
      letter-spacing: -0.01em;
    }
    .showcase-subtitle {
      font-size: 13px;
      color: var(--white-75);
      line-height: 1.4;
    }

    /* SECTION 3: APP HIGHLIGHTS / FEATURE TILES */
    .section-features {
      margin-bottom: 60px;
    }
    .features-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 20px;
    }
    .feat-card {
      background: linear-gradient(160deg, rgba(255, 255, 255, 0.06) 0%, rgba(255, 255, 255, 0.02) 100%);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid var(--glass-border);
      border-radius: var(--radius-md);
      padding: 24px;
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
    }
    .feat-card:hover {
      transform: translateY(-4px);
      border-color: rgba(245, 158, 11, 0.4);
      background: linear-gradient(160deg, rgba(255, 255, 255, 0.09) 0%, rgba(255, 255, 255, 0.03) 100%);
    }
    .feat-icon-box {
      width: 48px;
      height: 48px;
      border-radius: 14px;
      background: linear-gradient(135deg, #b91c1c, #7f1d1d);
      color: #fff;
      display: grid;
      place-items: center;
      margin-bottom: 16px;
      box-shadow: 0 8px 20px rgba(185, 28, 28, 0.4);
    }
    .feat-card h3 {
      font-size: 18px;
      font-weight: 800;
      color: var(--white);
      margin-bottom: 8px;
      letter-spacing: -0.01em;
    }
    .feat-card p {
      font-size: 14px;
      color: var(--white-75);
      line-height: 1.55;
    }

    /* SECTION 4: HOW TO INSTALL (3 SIMPLE STEPS) */
    .section-install {
      background: linear-gradient(180deg, rgba(35, 5, 5, 0.75) 0%, rgba(20, 3, 3, 0.95) 100%);
      backdrop-filter: blur(20px);
      border: 1px solid var(--glass-border);
      border-radius: var(--radius-lg);
      padding: 40px;
      margin-bottom: 60px;
      box-shadow: var(--shadow-soft);
    }
    .install-steps-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 24px;
      margin-top: 30px;
      position: relative;
    }
    .step-card {
      background: rgba(255, 255, 255, 0.04);
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: var(--radius-md);
      padding: 26px 22px;
      position: relative;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
    .step-number-badge {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--gold), var(--gold-dark));
      color: #1f0202;
      font-size: 16px;
      font-weight: 900;
      display: grid;
      place-items: center;
      box-shadow: 0 6px 16px rgba(245, 158, 11, 0.4);
    }
    .step-card h4 {
      font-size: 17px;
      font-weight: 800;
      color: var(--white);
    }
    .step-card p {
      font-size: 13.5px;
      color: var(--white-75);
      line-height: 1.5;
    }
    .step-tip {
      font-size: 12px;
      color: #fef08a;
      background: rgba(245, 158, 11, 0.1);
      border-left: 3px solid var(--gold);
      padding: 8px 12px;
      border-radius: 4px;
      margin-top: auto;
    }

    /* SECTION 5: APP SPECS & INFORMATION */
    .specs-card {
      background: rgba(255, 255, 255, 0.04);
      border: 1px solid var(--glass-border);
      border-radius: var(--radius-md);
      padding: 24px;
      margin-bottom: 60px;
    }
    .specs-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
      gap: 18px;
    }
    .spec-item {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .spec-label {
      font-size: 12px;
      color: var(--white-50);
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }
    .spec-value {
      font-size: 14.5px;
      color: var(--white);
      font-weight: 700;
    }

    /* Lightbox Modal */
    .lightbox-modal {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.92);
      backdrop-filter: blur(12px);
      z-index: 9999;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
      animation: fadeIn 0.2s ease-out;
    }
    .lightbox-modal.active {
      display: flex;
    }
    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }
    .lightbox-content {
      position: relative;
      max-width: 90vw;
      max-height: 88vh;
      display: flex;
      flex-direction: column;
      align-items: center;
    }
    .lightbox-img {
      max-height: 80vh;
      max-width: 90vw;
      object-fit: contain;
      border-radius: 18px;
      box-shadow: 0 25px 60px rgba(0, 0, 0, 0.8), 0 0 0 1px rgba(255, 255, 255, 0.15);
    }
    .lightbox-caption {
      margin-top: 14px;
      font-size: 15px;
      font-weight: 700;
      color: var(--white);
      text-align: center;
    }
    .lightbox-close {
      position: absolute;
      top: -18px;
      right: -18px;
      width: 44px;
      height: 44px;
      border-radius: 50%;
      background: #b91c1c;
      color: #fff;
      border: 2px solid #fff;
      display: grid;
      place-items: center;
      cursor: pointer;
      box-shadow: 0 6px 18px rgba(0,0,0,0.5);
      transition: 0.2s;
    }
    .lightbox-close:hover {
      transform: scale(1.1);
      background: #dc2626;
    }
    .lightbox-btn-prev, .lightbox-btn-next {
      position: absolute;
      top: 50%;
      transform: translateY(-50%);
      width: 50px;
      height: 50px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.15);
      border: 1px solid rgba(255, 255, 255, 0.3);
      color: #fff;
      display: grid;
      place-items: center;
      cursor: pointer;
      backdrop-filter: blur(8px);
      transition: 0.2s;
    }
    .lightbox-btn-prev:hover, .lightbox-btn-next:hover {
      background: rgba(255, 255, 255, 0.3);
      transform: translateY(-50%) scale(1.1);
    }
    .lightbox-btn-prev { left: -70px; }
    .lightbox-btn-next { right: -70px; }
    @media (max-width: 820px) {
      .lightbox-btn-prev { left: 10px; }
      .lightbox-btn-next { right: 10px; }
      .lightbox-close { top: 10px; right: 10px; }
    }

    /* Sticky Bottom Download Bar for Mobile */
    .sticky-download-bar {
      position: fixed;
      bottom: 0;
      left: 0;
      right: 0;
      background: rgba(30, 4, 4, 0.92);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border-top: 1px solid var(--glass-border);
      padding: 12px 20px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      z-index: 100;
      transform: translateY(110%);
      transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
      box-shadow: 0 -10px 30px rgba(0, 0, 0, 0.5);
    }
    .sticky-download-bar.visible {
      transform: translateY(0);
    }
    .sticky-app-meta {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .sticky-app-icon {
      width: 42px;
      height: 42px;
      border-radius: 10px;
      object-fit: cover;
      background: #fff;
    }
    .sticky-app-title {
      font-size: 15px;
      font-weight: 800;
      color: #fff;
    }
    .sticky-app-size {
      font-size: 12px;
      color: var(--gold);
      font-weight: 600;
    }

    /* Footer */
    .footer-section {
      text-align: center;
      padding-top: 30px;
      border-top: 1px solid rgba(255, 255, 255, 0.08);
      color: var(--white-50);
      font-size: 13px;
    }
    .footer-section strong {
      color: var(--white-75);
    }

    /* Responsive Adjustments */
    @media (max-width: 860px) {
      .main-container {
        padding: 16px 16px 90px;
      }
      .playstore-hero {
        grid-template-columns: 1fr;
        padding: 28px 20px;
        text-align: center;
        gap: 24px;
      }
      .hero-icon-col {
        margin: 0 auto;
        width: 100%;
        max-width: 280px;
      }
      .hero-title {
        font-size: clamp(1.85rem, 6.5vw, 2.8rem);
      }
      .hero-desc {
        margin: 0 auto;
        font-size: 14.5px;
      }
      .download-subtext {
        justify-content: center;
      }
      .update-install-note {
        margin-right: auto;
        margin-left: auto;
        text-align: left;
      }
      .section-install {
        padding: 28px 20px;
      }
    }

    @media (max-width: 640px) {
      .main-container {
        padding: 12px 12px 90px;
      }
      .nav-bar {
        padding: 10px 14px;
        margin-bottom: 24px;
      }
      .brand-logo {
        width: 32px;
        height: 32px;
      }
      .brand-name {
        font-size: 14px;
      }
      .brand-sub {
        font-size: 10px;
      }
      .nav-badge {
        padding: 5px 10px;
        font-size: 11px;
      }
      .playstore-hero {
        padding: 24px 14px;
        border-radius: 20px;
        margin-bottom: 32px;
      }
      .logo-collision-wrapper {
        height: 165px;
        max-width: 260px;
      }
      .logo-ccs {
        width: 135px;
        height: 135px;
      }
      .logo-bsit, .logo-cs {
        width: 72px;
        height: 72px;
      }
      .hero-title {
        font-size: clamp(1.6rem, 7.5vw, 2.1rem);
        letter-spacing: -0.02em;
        word-break: break-word;
      }
      .hero-dev {
        font-size: 13px;
      }
      .hero-desc {
        font-size: 13.5px;
        line-height: 1.55;
      }
      .play-metrics-strip {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
        padding: 12px;
        width: 100%;
      }
      .metric-item {
        min-width: 0;
        padding: 4px;
      }
      .metric-item:not(:last-child)::after {
        display: none;
      }
      .metric-val {
        font-size: 14.5px;
      }
      .metric-lbl {
        font-size: 10px;
      }
      .hero-actions {
        flex-direction: column;
        width: 100%;
        gap: 10px;
      }
      .btn-download-primary, .btn-secondary {
        width: 100%;
        justify-content: center;
        padding: 14px 18px;
        font-size: 15px;
      }
      .showcase-card {
        flex: 0 0 min(260px, 78vw);
      }
      .features-grid {
        grid-template-columns: 1fr;
        gap: 14px;
      }
      .feat-card {
        padding: 18px 16px;
      }
      .section-install {
        padding: 22px 14px;
        border-radius: 20px;
      }
      .install-steps-grid {
        grid-template-columns: 1fr;
        gap: 14px;
        margin-top: 18px;
      }
      .step-card {
        padding: 16px 14px;
      }
      .specs-card {
        padding: 18px 14px;
      }
      .specs-grid {
        grid-template-columns: 1fr 1fr;
        gap: 12px;
      }
      .lightbox-btn-prev, .lightbox-btn-next {
        width: 40px;
        height: 40px;
      }
      .lightbox-close {
        top: 6px;
        right: 6px;
        width: 38px;
        height: 38px;
      }
    }

    @media (max-width: 380px) {
      .logo-collision-wrapper {
        transform: scale(0.88);
        transform-origin: center center;
      }
      .hero-title {
        font-size: 1.5rem;
      }
      .specs-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <!-- Ambient background glow elements -->
  <div class="bg-glow-orb bg-glow-1" aria-hidden="true"></div>
  <div class="bg-glow-orb bg-glow-2" aria-hidden="true"></div>

  <div class="main-container">
    <!-- Brand Navigation Bar -->
    <header class="nav-bar">
      <div class="brand-link">
        <img src="/assets/favicon-48.png" width="38" height="38" alt="CCS Logo" class="brand-logo">
        <div class="brand-text">
          <span class="brand-name">PulseCONNECT</span>
          <span class="brand-sub">Laguna University</span>
        </div>
      </div>
      <div class="nav-badge">
        <span class="nav-badge-dot"></span>
        <span>Android APK</span>
      </div>
    </header>

    <!-- Google Play Store Style Hero Section -->
    <section class="playstore-hero" aria-label="App Overview">
      <div class="hero-icon-col">
        <div class="logo-collision-wrapper">
          <img src="/assets/BSIT.png" alt="BSIT Department" class="logo-bsit" />
          <img src="/assets/CS.png" alt="CS Department" class="logo-cs" />
          <div class="collision-flash"></div>
          <!-- Lightning & Spark Effects -->
          <div class="lightning-strike"></div>
          <div class="spark spark-1"></div>
          <div class="spark spark-2"></div>
          <div class="spark spark-3"></div>
          <div class="spark spark-4"></div>
          <img src="/assets/CCS.png" alt="College of Computing Studies" class="logo-ccs" />
        </div>
      </div>

      <div class="hero-info-col">
        <h1 class="hero-title"><?= htmlspecialchars($appName) ?></h1>
        <p class="hero-dev">College of Computing Studies · Laguna University</p>
        <p class="hero-desc">The all-in-one mobile companion for CCS students. Discover college events, access digital tickets with online scanning or optional offline QR check-in, and track seminar attendance with automatic sync.</p>

        <!-- Play Store Style Metrics Strip -->
        <div class="play-metrics-strip">
          <div class="metric-item">
            <div class="metric-val"><?= htmlspecialchars($fileSize) ?></div>
            <span class="metric-lbl">Download Size</span>
          </div>
          <div class="metric-item">
            <div class="metric-val"><?= htmlspecialchars($version) ?></div>
            <span class="metric-lbl">Current Build</span>
          </div>
          <div class="metric-item">
            <div class="metric-val">Choice</div>
            <span class="metric-lbl">Online &amp; Offline</span>
          </div>
          <div class="metric-item">
            <div class="metric-val">Android 7+</div>
            <span class="metric-lbl">Compatible</span>
          </div>
        </div>

        <!-- Download Buttons & CTA -->
        <div class="hero-actions" id="download">
          <a class="btn-download-primary" href="<?= htmlspecialchars($apkUrl) ?>" rel="noopener noreferrer" target="_blank">
            <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
            Download APK
          </a>
          <a class="btn-secondary" href="#installation-guide">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z"/></svg>
            How to Install
          </a>
        </div>
        <p class="download-subtext">
          <svg width="14" height="14" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
          Direct release build · Saves as <strong>PulseCONNECT.apk</strong> (<?= htmlspecialchars($fileSize) ?>)
        </p>
        <div class="update-install-note" role="note">
          <svg class="update-install-note-icon" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M4.93 19h14.14c1.54 0 2.5-1.67 1.73-3L13.73 3.75a2 2 0 00-3.46 0L3.2 16c-.77 1.33.19 3 1.73 3z"/></svg>
          <div class="update-install-note-copy">
            <strong>Important: installing an update?</strong>
            Uninstall the old PulseCONNECT version before downloading and installing this latest APK to avoid installation conflicts.
          </div>
        </div>
      </div>
    </section>

    <!-- SECTION: PLAY STORE INTERACTIVE SCREENSHOT SHOWCASE -->
    <section class="section-showcase" aria-labelledby="showcase-heading">
      <div class="section-header">
        <div class="section-title-wrap">
          <h2 id="showcase-heading">
            <svg width="24" height="24" fill="none" stroke="var(--gold)" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3"/></svg>
            App Showcase &amp; Previews
          </h2>
          <p>Swipe or drag across screenshots to preview the mobile app experience.</p>
        </div>
      </div>

      <div class="carousel-container">
        <div class="carousel-track" id="showcaseTrack">
          <!-- Slide 1: Campus Hub -->
          <div class="showcase-card" data-index="0" data-img="/assets/showcase/showcase_hub.png" data-title="Your Campus Hub" data-desc="Upcoming events, featured showcase cards, and quick check-in shortcuts.">
            <div class="showcase-img-wrap">
              <img src="/assets/showcase/showcase_hub.png" alt="PulseCONNECT Campus Hub screen" class="showcase-img" loading="lazy">
              <div class="showcase-zoom-hint" title="Click to enlarge">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607zM10.5 7.5v6m3-3h-6"/></svg>
              </div>
            </div>
            <div class="showcase-caption">
              <span class="showcase-badge">01 · Student Home</span>
              <h3 class="showcase-title">Your Campus Hub</h3>
              <p class="showcase-subtitle">Upcoming events, featured showcase cards, and quick check-in shortcuts.</p>
            </div>
          </div>

          <!-- Slide 2: Discover Events -->
          <div class="showcase-card" data-index="1" data-img="/assets/showcase/showcase_events.png" data-title="Discover Events" data-desc="Browse published CCS activities and stay in the loop.">
            <div class="showcase-img-wrap">
              <img src="/assets/showcase/showcase_events.png" alt="PulseCONNECT Discover Events screen" class="showcase-img" loading="lazy">
              <div class="showcase-zoom-hint" title="Click to enlarge">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607zM10.5 7.5v6m3-3h-6"/></svg>
              </div>
            </div>
            <div class="showcase-caption">
              <span class="showcase-badge">02 · Event Directory</span>
              <h3 class="showcase-title">Discover Events</h3>
              <p class="showcase-subtitle">Register for exhibits, seminars, and summits directly from your phone.</p>
            </div>
          </div>

          <!-- Slide 3: Digital Tickets -->
          <div class="showcase-card" data-index="2" data-img="/assets/showcase/showcase_tickets.png" data-title="Digital Tickets" data-desc="Secure e-tickets ready when you check in.">
            <div class="showcase-img-wrap">
              <img src="/assets/showcase/showcase_tickets.png" alt="PulseCONNECT Digital Tickets screen" class="showcase-img" loading="lazy">
              <div class="showcase-zoom-hint" title="Click to enlarge">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607zM10.5 7.5v6m3-3h-6"/></svg>
              </div>
            </div>
            <div class="showcase-caption">
              <span class="showcase-badge">03 · E-Ticket Wallet</span>
              <h3 class="showcase-title">Digital Tickets</h3>
              <p class="showcase-subtitle">Unique encrypted QR tickets that work seamlessly online or offline.</p>
            </div>
          </div>

          <!-- Slide 4: Your Profile -->
          <div class="showcase-card" data-index="3" data-img="/assets/showcase/showcase_profile.png" data-title="Student Profile" data-desc="Certificates, security, and account controls.">
            <div class="showcase-img-wrap">
              <img src="/assets/showcase/showcase_profile.png" alt="PulseCONNECT Student Profile screen" class="showcase-img" loading="lazy">
              <div class="showcase-zoom-hint" title="Click to enlarge">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607zM10.5 7.5v6m3-3h-6"/></svg>
              </div>
            </div>
            <div class="showcase-caption">
              <span class="showcase-badge">04 · Academic Profile</span>
              <h3 class="showcase-title">Your Profile</h3>
              <p class="showcase-subtitle">Certificates, class schedules, security credentials, and department records.</p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- SECTION: FEATURE HIGHLIGHTS -->
    <section class="section-features" aria-label="Key Features">
      <div class="section-header">
        <div class="section-title-wrap">
          <h2>Why Students Love PulseCONNECT</h2>
          <p>Engineered exclusively for computing students and faculty of Laguna University.</p>
        </div>
      </div>
      <div class="features-grid">
        <div class="feat-card">
          <div class="feat-icon-box">
            <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 6v.75m0 3v.75m0 3v.75m0 3V18m-9-5.25h5.25M7.5 15h3M3.375 5.25c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 010 5.198v3.026c0 .621.504 1.125 1.125 1.125h17.25c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 010-5.198V6.375c0-.621-.504-1.125-1.125-1.125H3.375z"/></svg>
          </div>
          <h3>Offline E-Tickets</h3>
          <p>Tickets and QR passes persist locally on your phone. Even when campus WiFi drops or mobile data is disabled, your tickets are always ready for scanning.</p>
        </div>

        <div class="feat-card">
          <div class="feat-icon-box">
            <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.5A.75.75 0 014.5 3.75h15a.75.75 0 01.75.75v15a.75.75 0 01-.75.75h-15a.75.75 0 01-.75-.75v-15zM8.25 8.25h7.5M8.25 12h7.5M8.25 15.75h4.5"/></svg>
          </div>
          <h3>Self Check-In Attendance</h3>
          <p>Scan event QRs online, or choose the optional offline mode if you prefer checking in without mobile data. Flexible scanning tailored to campus connectivity.</p>
        </div>

        <div class="feat-card">
          <div class="feat-icon-box">
            <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5A2.25 2.25 0 015.25 5.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
          </div>
          <h3>Live Event Feed</h3>
          <p>Discover published CCS departmental activities, exhibitions, and coding summits tailored specifically to your year level and course specialization.</p>
        </div>

        <div class="feat-card">
          <div class="feat-icon-box">
            <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z"/></svg>
          </div>
          <h3>Certificate Portfolio</h3>
          <p>Seamlessly generate, download, and view digital certificates of attendance earned from accomplished events directly from your profile.</p>
        </div>
      </div>
    </section>

    <!-- SECTION: STEP-BY-STEP INSTALLATION GUIDE -->
    <section class="section-install" id="installation-guide" aria-labelledby="install-heading">
      <div class="section-title-wrap">
        <h2 id="install-heading">How to Install on Android</h2>
        <p>Simple 3-step setup. Because this is a direct campus release, you can install the APK directly onto your device.</p>
      </div>

      <div class="install-steps-grid">
        <div class="step-card">
          <div class="step-number-badge">1</div>
          <h4>Download the APK</h4>
          <p>Tap the <strong>Download APK</strong> button above to download <strong>PulseCONNECT.apk</strong> (<?= htmlspecialchars($fileSize) ?>) on MediaFire.</p>
          <div class="step-tip">File: PulseCONNECT.apk (75.53 MB)</div>
        </div>

        <div class="step-card">
          <div class="step-number-badge">2</div>
          <h4>Allow Unknown Sources</h4>
          <p>When opening <strong>PulseCONNECT.apk</strong>, if Android prompts <em>"For security, your phone is not allowed to install unknown apps"</em>, tap <strong>Settings</strong> and toggle <strong>Allow from this source</strong>.</p>
          <div class="step-tip">This is normal for all direct APK campus installs.</div>
        </div>

        <div class="step-card">
          <div class="step-number-badge">3</div>
          <h4>Install &amp; Log In</h4>
          <p>Tap <strong>Install</strong>. Once completed, open PulseCONNECT proceed to create an account then enter actual student ID and set your account.</p>
          <div class="step-tip">Your data will automatically sync with your student account!</div>
        </div>
      </div>
    </section>

    <!-- SECTION: APP SPECIFICATIONS -->
    <section class="specs-card" aria-label="Technical Details">
      <div class="specs-grid">
        <div class="spec-item">
          <span class="spec-label">Application</span>
          <span class="spec-value"><?= htmlspecialchars($appName) ?></span>
        </div>
        <div class="spec-item">
          <span class="spec-label">Current Version</span>
          <span class="spec-value"><?= htmlspecialchars($version) ?></span>
        </div>
        <div class="spec-item">
          <span class="spec-label">Operating System</span>
          <span class="spec-value">Android 7.0 and up</span>
        </div>
        <div class="spec-item">
          <span class="spec-label">File Name</span>
          <span class="spec-value">PulseCONNECT.apk</span>
        </div>
        <div class="spec-item">
          <span class="spec-label">Developers</span>
          <span class="spec-value">MARK STEPHEN ESPINOSA / CIAN CLYDE TANDANG</span>
        </div>
      </div>
    </section>

    <!-- Footer -->
    <footer class="footer-section">
      <p>Mobile Application for <strong>College of Computing Studies</strong> · Laguna University</p>
      <p style="margin-top: 6px; font-size: 12px; color: var(--white-50);">Student Companion for Laguna University Events. All rights reserved.</p>
    </footer>
  </div>

  <!-- Mobile Sticky Download Bar -->
  <div class="sticky-download-bar" id="stickyDownloadBar">
    <div class="sticky-app-meta">
      <img src="/assets/apple-touch-icon.png" alt="" class="sticky-app-icon">
      <div>
        <div class="sticky-app-title">PulseCONNECT</div>
        <div class="sticky-app-size">Android APK · <?= htmlspecialchars($fileSize) ?></div>
      </div>
    </div>
    <a class="btn-download-primary" style="padding: 10px 22px; font-size: 14px;" href="<?= htmlspecialchars($apkUrl) ?>" rel="noopener noreferrer" target="_blank">
      Download
    </a>
  </div>

  <!-- Interactive Lightbox Modal for Screenshots -->
  <div class="lightbox-modal" id="lightboxModal" role="dialog" aria-modal="true">
    <div class="lightbox-content">
      <button class="lightbox-close" id="lightboxCloseBtn" aria-label="Close image preview">
        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
      <button class="lightbox-btn-prev" id="lightboxPrevBtn" aria-label="Previous image">
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
      </button>
      <img src="" alt="" class="lightbox-img" id="lightboxImg">
      <div class="lightbox-caption" id="lightboxCaption"></div>
      <button class="lightbox-btn-next" id="lightboxNextBtn" aria-label="Next image">
        <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
      </button>
    </div>
  </div>

  <!-- JavaScript for Carousel & Lightbox Interaction -->
  <script>
    (function() {
      // 1. Showcase Slider Drag & Swipe
      const track = document.getElementById('showcaseTrack');

      if (track) {
        // Mouse Drag to Scroll
        let isDown = false;
        let startX;
        let scrollLeft;

        track.addEventListener('mousedown', (e) => {
          isDown = true;
          startX = e.pageX - track.offsetLeft;
          scrollLeft = track.scrollLeft;
        });
        track.addEventListener('mouseleave', () => { isDown = false; });
        track.addEventListener('mouseup', () => { isDown = false; });
        track.addEventListener('mousemove', (e) => {
          if (!isDown) return;
          e.preventDefault();
          const x = e.pageX - track.offsetLeft;
          const walk = (x - startX) * 1.5;
          track.scrollLeft = scrollLeft - walk;
        });
      }

      // 2. Lightbox Functionality
      const cards = document.querySelectorAll('.showcase-card');
      const modal = document.getElementById('lightboxModal');
      const modalImg = document.getElementById('lightboxImg');
      const modalCaption = document.getElementById('lightboxCaption');
      const closeBtn = document.getElementById('lightboxCloseBtn');
      const lbPrevBtn = document.getElementById('lightboxPrevBtn');
      const lbNextBtn = document.getElementById('lightboxNextBtn');

      let currentIndex = 0;
      const imagesData = Array.from(cards).map(card => ({
        src: card.getAttribute('data-img'),
        title: card.getAttribute('data-title'),
        desc: card.getAttribute('data-desc')
      }));

      function showLightbox(index) {
        if (index < 0) index = imagesData.length - 1;
        if (index >= imagesData.length) index = 0;
        currentIndex = index;

        const data = imagesData[currentIndex];
        modalImg.src = data.src;
        modalCaption.textContent = `${data.title} — ${data.desc}`;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
      }

      function closeLightbox() {
        modal.classList.remove('active');
        document.body.style.overflow = '';
      }

      cards.forEach((card, idx) => {
        card.addEventListener('click', () => {
          showLightbox(idx);
        });
      });

      if (closeBtn) closeBtn.addEventListener('click', closeLightbox);
      if (modal) {
        modal.addEventListener('click', (e) => {
          if (e.target === modal) closeLightbox();
        });
      }

      if (lbPrevBtn) {
        lbPrevBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          showLightbox(currentIndex - 1);
        });
      }

      if (lbNextBtn) {
        lbNextBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          showLightbox(currentIndex + 1);
        });
      }

      // Keyboard navigation
      window.addEventListener('keydown', (e) => {
        if (!modal.classList.contains('active')) return;
        if (e.key === 'Escape') closeLightbox();
        if (e.key === 'ArrowLeft') showLightbox(currentIndex - 1);
        if (e.key === 'ArrowRight') showLightbox(currentIndex + 1);
      });

      // 3. Sticky Download Bar Trigger on Scroll
      const stickyBar = document.getElementById('stickyDownloadBar');
      const heroSection = document.querySelector('.playstore-hero');

      if (stickyBar && heroSection) {
        window.addEventListener('scroll', () => {
          const heroRect = heroSection.getBoundingClientRect();
          if (heroRect.bottom < 80) {
            stickyBar.classList.add('visible');
          } else {
            stickyBar.classList.remove('visible');
          }
        }, { passive: true });
      }
    })();
  </script>
</body>
</html>

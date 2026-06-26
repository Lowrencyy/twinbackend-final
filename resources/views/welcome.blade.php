<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>TelcoVantage Philippines</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            brand: '#0A5C3B',
            brand2: '#2563EB',
            soft: '#64748b'
          },
          fontFamily: {
            display: ['Sora', 'sans-serif'],
            body: ['Manrope', 'sans-serif']
          }
        }
      }
    }
  </script>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@splidejs/splide@4.1.4/dist/css/splide.min.css" />

  <style>
    :root {
      --brand: #0A5C3B;
      --brand2: #2563EB;
      --text-soft: #64748b;
    }

    * { box-sizing: border-box; }
    html { scroll-behavior: smooth; }

    body {
      margin: 0;
      font-family: 'Manrope', sans-serif;
      background: linear-gradient(135deg, #f0f0f0 0%, #edf6f1 35%, #eef6fb 68%, #f4fbf7 100%);
      background-size: 220% 220%;
      animation: bodyGradientShift 16s ease-in-out infinite;
      color: #0f172a;
      overflow-x: hidden;
    }

    .headline {
      font-family: 'Sora', sans-serif;
      letter-spacing: -0.05em;
    }

    .brand-text {
      color: #0A5C3B;
    }

    .gradient-line {
      background: linear-gradient(90deg, transparent, rgba(10,92,59,0.95), rgba(37,99,235,0.75), transparent);
    }

    .nav-shell {
      background: linear-gradient(135deg, rgba(255,255,255,0.94), rgba(235,247,241,0.84), rgba(234,246,255,0.82));
      border: 1px solid rgba(10,92,59,0.10);
      box-shadow: 0 18px 40px rgba(10,92,59,0.08);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      transition: box-shadow .3s ease, background .3s ease, border-color .3s ease;
    }

    .nav-scrolled {
      background: transparent;
      border-bottom: 1px solid rgba(15,23,42,0.06);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
    }

    .nav-scrolled .nav-shell {
      background: linear-gradient(135deg, rgba(255,255,255,0.98), rgba(239,248,243,0.90), rgba(236,246,255,0.88));
      border-color: rgba(10,92,59,0.12);
      box-shadow: 0 18px 44px rgba(10,92,59,0.10);
    }

    #mobile-menu {
      height: 0;
      overflow: hidden;
      opacity: 0;
    }

    .glass-card {
      border: 1px solid rgba(15,23,42,0.08);
      background: linear-gradient(135deg, rgba(255,255,255,0.92), rgba(238,248,243,0.78), rgba(234,246,255,0.66));
      box-shadow: 0 18px 40px rgba(15,23,42,0.06);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
    }

    .service-card,
    .project-card,
    .value-card,
    .contact-card,
    .platform-card {
      transition: transform .35s ease, box-shadow .35s ease, border-color .35s ease;
    }

    .service-card:hover,
    .project-card:hover,
    .value-card:hover,
    .contact-card:hover,
    .platform-card:hover {
      transform: translateY(-6px);
      box-shadow: 0 18px 40px rgba(15,23,42,0.08);
    }

    .service-icon {
      transition: transform .35s ease;
    }

    .service-card:hover .service-icon {
      transform: translateY(-4px) scale(1.05);
    }

    .splide__arrow {
      background: linear-gradient(135deg, rgba(255,255,255,0.96), rgba(236,248,242,0.88), rgba(234,246,255,0.86));
      border: 1px solid rgba(10,92,59,0.12);
      width: 2.7rem;
      height: 2.7rem;
      opacity: 1;
      box-shadow: 0 10px 24px rgba(10,92,59,0.08);
    }

    .splide__arrow svg {
      fill: #0A5C3B;
      width: 1rem;
      height: 1rem;
    }

    .splide__pagination {
      bottom: -1rem;
      gap: 0.4rem;
    }

    .splide__pagination__page {
      background: rgba(10,92,59,0.22);
      width: 0.6rem;
      height: 0.6rem;
      margin: 0;
      opacity: 1;
      transition: all .25s ease;
    }

    .splide__pagination__page.is-active {
      background: linear-gradient(135deg, #0A5C3B, #2563EB);
      width: 1.8rem;
      border-radius: 999px;
      transform: none;
    }

    @keyframes bodyGradientShift {
      0% { background-position: 0% 50%; }
      25% { background-position: 100% 50%; }
      50% { background-position: 100% 100%; }
      75% { background-position: 0% 100%; }
      100% { background-position: 0% 50%; }
    }

    @media (max-width: 1024px) {
      #hero-logo-wrap {
        max-width: 500px;
      }
    }

    @media (max-width: 768px) {
      #home {
        min-height: auto;
        padding-bottom: 2rem;
      }

      .headline {
        letter-spacing: -0.04em;
      }

      #hero-logo-wrap {
        max-width: 320px;
      }

      .platform-wrapper {
        grid-template-columns: 1fr !important;
        gap: 2rem !important;
      }

      .platform-text {
        order: 2;
        text-align: left;
      }

      .platform-video {
        order: 1;
        justify-content: center;
      }

      .platform-text .section-line {
        margin-left: 0;
      }

      .platform-text .feature-row {
        align-items: flex-start;
      }
    }

    @media (min-width: 1280px) {
      #hero-logo-wrap {
        max-width: 980px;
      }
    }

    @keyframes spin {
      to { transform: rotate(360deg); }
    }
  </style>
</head>
<body>
  <header id="site-header" class="fixed inset-x-0 top-0 z-50 transition-all duration-300">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
      <div class="nav-shell mt-3 flex items-center justify-between rounded-2xl px-4 py-3">
        <a href="#home" class="flex items-center gap-3 flex-shrink-0">
          <img
            src="{{ asset('assets/images/logo-dark.png') }}"
            alt="TelcoVantage Philippines Logo"
            class="h-8 sm:h-9 lg:h-10 w-auto object-contain"
            onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
          />
          <div class="hidden flex-col leading-tight">
            <span class="text-sm font-black tracking-[0.28em] brand-text">TELCOVANTAGE</span>
            <span class="text-[9px] tracking-[0.35em] brand-text uppercase">Philippines</span>
          </div>
        </a>

        <nav class="hidden items-center gap-7 lg:flex text-sm text-slate-700">
          <a href="#about" class="transition hover:text-[#0A5C3B]">About</a>
          <a href="#vision" class="transition hover:text-[#0A5C3B]">Mission, Vision & Core Values</a>
          <a href="#platform" class="transition hover:text-[#0A5C3B]">Platform</a>
          <a href="#services" class="transition hover:text-[#0A5C3B]">Services</a>
          <a href="#projects" class="transition hover:text-[#0A5C3B]">Projects</a>
          <a href="#contact" class="transition hover:text-[#0A5C3B]">Contact</a>
        </nav>

        <div class="flex items-center gap-3">
          <a href="https://dashboard.telcovantage.com" target="_blank" rel="noopener noreferrer" class="hidden sm:inline-flex items-center rounded-full bg-[#0A5C3B] px-5 py-2.5 text-sm font-bold text-white transition hover:bg-[#0c6a43] shadow-[0_12px_26px_rgba(10,92,59,0.16)]">
            Login
          </a>

          <button id="menu-btn" class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-white/10 bg-white/5 text-[#0A5C3B] lg:hidden" aria-label="Toggle menu">
            <svg id="menu-open" width="20" height="20" viewBox="0 0 24 24" fill="none">
              <path d="M4 7H20M4 12H20M4 17H20" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
            </svg>
            <svg id="menu-close" width="20" height="20" viewBox="0 0 24 24" fill="none" class="hidden">
              <path d="M6 6L18 18M18 6L6 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
            </svg>
          </button>
        </div>
      </div>

      <div id="mobile-menu" class="mx-1 mt-2 rounded-2xl border border-[rgba(10,92,59,0.10)] bg-[linear-gradient(135deg,rgba(255,255,255,0.95),rgba(236,248,242,0.88),rgba(234,246,255,0.82))] px-4 backdrop-blur-xl lg:hidden">
        <div class="flex flex-col py-4 text-sm text-[#0A5C3B]/80">
          <a class="rounded-xl px-3 py-3 hover:bg-white/5" href="#about">About</a>
          <a class="rounded-xl px-3 py-3 hover:bg-white/5" href="#vision">Mission, Vision & Core Values</a>
          <a class="rounded-xl px-3 py-3 hover:bg-white/5" href="#platform">Platform</a>
          <a class="rounded-xl px-3 py-3 hover:bg-white/5" href="#services">Services</a>
          <a class="rounded-xl px-3 py-3 hover:bg-white/5" href="#projects">Projects</a>
          <a class="rounded-xl px-3 py-3 hover:bg-white/5" href="#contact">Contact</a>
        </div>
      </div>
    </div>
  </header>

  <main>
    <section id="home" class="min-h-screen flex items-center pt-24 pb-10 sm:pb-16">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 w-full">
        <div class="grid items-center gap-10 lg:grid-cols-[0.9fr_1.1fr]">
          <div class="hero-copy text-center lg:text-left order-2 lg:order-1">
            <p class="text-xs sm:text-sm md:text-base font-semibold tracking-[0.22em] text-gray-600 uppercase mb-3">
              TelcoVantage
            </p>

            <h1 class="headline text-[2.4rem] leading-[0.95] sm:text-[3.4rem] md:text-[4.4rem] lg:text-[5rem] font-extrabold uppercase brand-text mb-5">
              Philippines
            </h1>

            <p class="text-gray-600 text-sm sm:text-base lg:text-[1.15rem] max-w-xl mx-auto lg:mx-0 leading-relaxed">
              The most trusted and innovative telecom service partner, empowering businesses with seamless,
              efficient, and sustainable connectivity solutions.
            </p>

            <div class="mt-8 flex flex-col sm:flex-row gap-3 sm:gap-4 justify-center lg:justify-start">
              <a href="#about" class="w-full sm:w-auto px-8 py-3.5 rounded-full bg-white border border-gray-300 text-slate-900 font-semibold hover:border-[#0A5C3B] hover:text-[#0A5C3B] transition text-base text-center">
                Learn More
              </a>

              <a href="#contact" class="w-full sm:w-auto px-8 py-3.5 rounded-full border-2 border-slate-800 text-slate-900 font-semibold hover:bg-slate-900 hover:text-white transition text-base text-center">
                Contact Us
              </a>
            </div>
          </div>

          <div class="hero-visual flex justify-center lg:justify-end order-1 lg:order-2">
            <div id="hero-logo-wrap" class="w-full max-w-[340px] sm:max-w-[440px] md:max-w-[560px] lg:max-w-[780px] xl:max-w-[980px]">
              <img
                src="{{ asset('assets/images/logo.png') }}"
                alt="TelcoVantage 3D Logo"
                class="w-full h-auto object-contain mx-auto drop-shadow-[0_28px_52px_rgba(0,0,0,0.16)]"
                id="hero-img"
                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
              />

              <div class="hidden items-center justify-center w-full min-h-[260px] sm:min-h-[320px] md:min-h-[420px] rounded-3xl border border-slate-200 bg-white shadow-sm">
                <div class="text-center px-6">
                  <img src="{{ asset('assets/images/logo-dark.png') }}" alt="TelcoVantage Philippines" class="mx-auto h-12 w-auto object-contain mb-5" />
                  <p class="headline text-3xl sm:text-4xl md:text-5xl font-extrabold brand-text">TELCOVANTAGE</p>
                  <p class="mt-2 text-xs tracking-[0.35em] uppercase brand-text">Philippines</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section id="about" class="py-20 md:py-28">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="grid items-center gap-12 lg:grid-cols-[0.95fr_1.05fr]">
          <div class="reveal-left text-center md:text-left order-2 lg:order-1">
            <p class="text-xs font-bold tracking-[0.3em] text-gray-500 uppercase mb-3">ABOUT OUR COMPANY</p>
            <h2 class="headline text-3xl sm:text-4xl font-extrabold uppercase brand-text mb-2">TELCOVANTAGE</h2>
            <div class="w-40 h-0.5 bg-[#0A5C3B] mx-auto md:mx-0 mb-6"></div>

            <div class="text-gray-600 text-sm sm:text-base leading-relaxed space-y-4 max-w-xl mx-auto md:mx-0">
              <p>
                TelcoVantage is a leading telecommunications service provider in the Philippines, dedicated to delivering
                cutting-edge solutions that empower businesses to thrive in the digital age.
              </p>
              <p>
                With our extensive expertise and commitment to excellence, we help organizations optimize their
                telecommunications infrastructure and embrace technological innovation.
              </p>
            </div>

            <a href="#services" class="mt-8 inline-flex items-center gap-2 px-7 py-3 rounded-full text-sm font-semibold text-slate-900 hover:text-[#0A5C3B] transition-colors">
              Our Services
              <span>→</span>
            </a>
          </div>

          <div class="reveal-right flex justify-center lg:justify-end order-1 lg:order-2">
            <div class="relative w-full max-w-sm lg:max-w-md xl:max-w-lg">
              <img src="{{ asset('assets/images/about-us.png') }}" alt="TelcoVantage About Us" class="w-full rounded-[28px] shadow-lg block" style="object-fit:contain;height:auto;" />
            </div>
          </div>
        </div>
      </div>
    </section>

    <section id="vision" class="py-24 sm:py-28 bg-transparent">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="reveal-up text-center">
          <p class="text-xs font-semibold uppercase tracking-[0.32em] text-[#0A5C3B]/65">Who We Are</p>
          <h2 class="headline mt-4 text-4xl font-extrabold brand-text sm:text-5xl">Mission, Vision &amp; Core Values</h2>
          <div class="gradient-line mx-auto mt-6 h-px w-56"></div>
        </div>

        <div class="mt-14 grid gap-6 lg:grid-cols-2">
          <div class="value-card reveal-left rounded-[2rem] border border-[rgba(10,92,59,0.10)] bg-[linear-gradient(135deg,rgba(255,255,255,0.88),rgba(232,247,239,0.80),rgba(234,246,255,0.44))] p-8 lg:p-10 shadow-[0_18px_40px_rgba(10,92,59,0.08)] backdrop-blur-xl">
            <div class="mb-6 flex h-14 w-14 items-center justify-center rounded-2xl bg-[linear-gradient(135deg,#0A5C3B,#3BB58A)] text-white shadow-[0_16px_30px_rgba(10,92,59,0.18)]">
              <i class="fa-solid fa-bolt text-xl"></i>
            </div>
            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-[#0A5C3B]/70">Our Mission</p>
            <h3 class="headline mt-4 text-2xl font-bold text-slate-900">Empowering businesses through seamless connectivity.</h3>
            <p class="mt-5 text-sm leading-8 text-slate-600">
              Our mission is to deliver efficient, sustainable, and high-performing telecommunications solutions that help
              organizations optimize infrastructure, improve reliability, and stay competitive in a connected world.
            </p>
          </div>

          <div class="value-card reveal-right rounded-[2rem] border border-[rgba(37,99,235,0.10)] bg-[linear-gradient(135deg,rgba(255,255,255,0.88),rgba(236,248,255,0.80),rgba(232,247,239,0.58))] p-8 lg:p-10 shadow-[0_18px_40px_rgba(37,99,235,0.06)] backdrop-blur-xl">
            <div class="mb-6 flex h-14 w-14 items-center justify-center rounded-2xl bg-[linear-gradient(135deg,#DBECFF,#EEF7FF)] text-[#2563EB] shadow-[0_12px_24px_rgba(37,99,235,0.08)]">
              <i class="fa-solid fa-bullseye text-xl"></i>
            </div>
            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-[#2563EB]/70">Our Vision</p>
            <h3 class="headline mt-4 text-2xl font-bold text-slate-900">Leading the future of telecommunications in the Philippines.</h3>
            <p class="mt-5 text-sm leading-8 text-slate-600">
              Our vision is to become the most trusted and innovative telecom service provider in the country by enabling
              world-class connectivity that drives growth, opportunity, and long-term digital transformation.
            </p>
          </div>
        </div>

        <div class="mt-16 text-center reveal-up">
          <p class="text-xs font-semibold uppercase tracking-[0.28em] text-[#0A5C3B]/70">Our Core Values</p>
          <h3 class="headline mt-3 text-2xl sm:text-3xl font-extrabold text-slate-900">The values that guide how we work</h3>
          <p class="mt-4 max-w-2xl mx-auto text-sm sm:text-base leading-7 text-slate-600">
            These principles shape our culture, strengthen our teams, and guide every solution we deliver for our clients and partners.
          </p>
        </div>

        <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4 stagger-group">
          <div class="stagger-item glass-card rounded-2xl p-6 text-center text-slate-900">
            <div class="mb-3 text-[#0A5C3B] text-xl"><i class="fa-solid fa-shield-heart"></i></div>
            <div class="font-bold text-base">Innovation</div>
            <p class="mt-2 text-xs leading-6 text-slate-500">We act with honesty, accountability, and professionalism in every engagement.</p>
          </div>

          <div class="stagger-item glass-card rounded-2xl p-6 text-center text-slate-900">
            <div class="mb-3 text-[#2563EB] text-xl"><i class="fa-solid fa-lightbulb"></i></div>
            <div class="font-bold text-base">Customer Centricity</div>
            <p class="mt-2 text-xs leading-6 text-slate-500">We embrace smarter technologies and better ways of solving field and network challenges.</p>
          </div>

          <div class="stagger-item glass-card rounded-2xl p-6 text-center text-slate-900">
            <div class="mb-3 text-[#0A5C3B] text-xl"><i class="fa-solid fa-award"></i></div>
            <div class="font-bold text-base">Sustainability</div>
            <p class="mt-2 text-xs leading-6 text-slate-500">We aim for high-quality execution, reliable service, and continuous improvement.</p>
          </div>

          <div class="stagger-item glass-card rounded-2xl p-6 text-center text-slate-900">
            <div class="mb-3 text-[#2563EB] text-xl"><i class="fa-solid fa-leaf"></i></div>
            <div class="font-bold text-base">Operational Efficiency</div>
            <p class="mt-2 text-xs leading-6 text-slate-500">We support long-term growth through efficient operations and responsible solutions.</p>
          </div>
          
           <div class="stagger-item glass-card rounded-2xl p-6 text-center text-slate-900">
            <div class="mb-3 text-[#2563EB] text-xl"><i class="fa-solid fa-leaf"></i></div>
            <div class="font-bold text-base">Respect</div>
            <p class="mt-2 text-xs leading-6 text-slate-500">We support long-term growth through efficient operations and responsible solutions.</p>
          </div>
        </div>
      </div>
    </section>

    <section id="platform" class="py-20 md:py-28">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="platform-wrapper grid items-center gap-12 lg:grid-cols-[0.95fr_1.05fr]">

          <div class="platform-text reveal-left text-left order-2 lg:order-1">
            <p class="text-xs font-bold tracking-[0.3em] text-gray-500 uppercase mb-3">What We Do</p>
            <h2 class="headline text-3xl sm:text-4xl font-extrabold uppercase brand-text mb-2">Teardown Process</h2>
            <div class="section-line w-40 h-0.5 bg-[#0A5C3B] mb-6"></div>

            <div class="text-gray-600 text-sm sm:text-base leading-relaxed space-y-4 max-w-xl">
             <p>TelcoVantage delivers a structured and technology-driven teardown process designed to ensure safe, efficient, and fully traceable asset recovery across all project sites. From initial assessment and field validation to dismantling, hauling, inventory management, and reporting, every stage is monitored in real time to maintain operational visibility and compliance.</p>
             
                 <p>Our platform enables seamless coordination between field teams, subcontractors, warehouse operations, and project management — reducing delays, improving accountability, and ensuring accurate documentation throughout the entire lifecycle of the project.</p>
            </div>

            <div class="mt-6 space-y-4 max-w-xl">
              <div class="feature-row flex items-start gap-3">
                <span class="mt-1 text-[#0A5C3B]"><i class="fa-solid fa-map-location-dot"></i></span>
                <div>
                  <div class="text-sm font-bold text-slate-900">Live Map Tracking</div>
                  <div class="text-xs text-slate-500 mt-0.5">Real-time pole and span visibility across all project sites.</div>
                </div>
              </div>

              <div class="feature-row flex items-start gap-3">
                <span class="mt-1 text-[#2563EB]"><i class="fa-solid fa-file-lines"></i></span>
                <div>
                  <div class="text-sm font-bold text-slate-900">Automated Reporting</div>
                  <div class="text-xs text-slate-500 mt-0.5">Teardown logs and pole reports generated instantly.</div>
                </div>
              </div>

              <div class="feature-row flex items-start gap-3">
                <span class="mt-1 text-[#0A5C3B]"><i class="fa-solid fa-list-check"></i></span>
                <div>
                  <div class="text-sm font-bold text-slate-900">Progress Monitoring</div>
                  <div class="text-xs text-slate-500 mt-0.5">Track completion per node, span, and project.</div>
                </div>
              </div>

              <div class="feature-row flex items-start gap-3">
                <span class="mt-1 text-[#2563EB]"><i class="fa-solid fa-users"></i></span>
                <div>
                  <div class="text-sm font-bold text-slate-900">Team Coordination</div>
                  <div class="text-xs text-slate-500 mt-0.5">Manage crews and subcontractors with ease.</div>
                </div>
              </div>
            </div>
          </div>

          <div class="platform-video reveal-right flex justify-center lg:justify-end order-1 lg:order-2">
            <div class="platform-card relative w-full max-w-sm lg:max-w-md" style="aspect-ratio:9/16;border-radius:28px;overflow:hidden;background:#0f172a;box-shadow:0 24px 64px rgba(15,23,42,.22);">
              <video controls autoplay muted loop playsinline style="width:100%;height:100%;object-fit:contain;">
                <source src="{{ asset('assets/images/teardown.mp4') }}" type="video/mp4">
              </video>
            </div>
          </div>

        </div>
      </div>
    </section>

    {{-- AsBuilt IQ — connection between teardown process and data export --}}
    <section class="py-16 md:py-24 bg-transparent">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">

        <div class="reveal-up text-center mb-10">
          <p class="text-xs font-bold tracking-[0.3em] text-gray-500 uppercase mb-3">Powered by TelcoVantage</p>
          <h2 class="headline text-3xl sm:text-4xl font-extrabold brand-text">Twinbackend <span class="text-[#2563EB]">×</span> Telcovantage Polemaster</h2>
          <div class="gradient-line mx-auto mt-4 h-px w-48"></div>
          <p class="mt-5 max-w-2xl mx-auto text-sm sm:text-base leading-7 text-slate-600">
            The tool linemen use on the ground — scan, map, and export pole and span data directly from the field into the TelcoVantage platform in real time.
          </p>

          <div class="mt-7 inline-flex items-center gap-2 rounded-full border border-[rgba(10,92,59,0.15)] bg-white/70 px-5 py-2 shadow-sm">
            <i class="fa-solid fa-layer-group text-[#0A5C3B] text-xs"></i>
            <span class="text-xs font-bold tracking-[0.22em] uppercase text-slate-600">Our Applications &amp; Tools</span>
          </div>
        </div>

        <div class="reveal-up flex justify-center">
          <div class="relative w-full max-w-5xl">
            <div class="glass-card rounded-[2rem] overflow-hidden shadow-[0_32px_80px_rgba(10,92,59,0.12)] border border-[rgba(10,92,59,0.10)]">
              <img
                src="/assets/images/asbuilt-app.png"
                alt="Twinbackend × Polemaster"
                class="w-full h-auto object-contain block"
              />
            </div>

            {{-- connector label --}}
            <div class="absolute -bottom-5 left-1/2 -translate-x-1/2 flex items-center gap-2 rounded-full bg-white border border-[rgba(10,92,59,0.14)] px-5 py-2 shadow-md text-xs font-semibold text-[#0A5C3B] whitespace-nowrap">
              <i class="fa-solid fa-arrow-up-from-bracket text-[#2563EB]"></i>
              Polemaster field data → synced to Twinbackend dashboard
            </div>
          </div>
        </div>

      </div>
    </section>

    <section id="services" class="bg-transparent py-20 md:py-28">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="reveal-up text-center mb-14">
          <p class="text-xs font-bold tracking-[0.3em] text-gray-500 uppercase mb-3">WHAT WE OFFER</p>
          <h2 class="headline text-3xl sm:text-4xl font-extrabold uppercase brand-text mb-4">Our Services</h2>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-6 max-w-7xl mx-auto stagger-group">
          <article class="stagger-item glass-card rounded-2xl p-6 text-center text-slate-900">
            <div class="mb-3 text-[#0A5C3B] text-xl"><i class="fa-solid fa-tower-broadcast"></i></div>
            <div class="font-bold text-base mb-2">Fixed And Wireless Network Rollout</div>
            <p class="text-xs leading-6 text-slate-500">We provide end-to-end deployment support for both fixed and wireless networks. By driving precise engineering planning, on-site installation oversight, and seamless system integration, we help operators accelerate time-to-market while ensuring structural reliability and network readiness.</p>
          </article>

          <article class="stagger-item glass-card rounded-2xl p-6 text-center text-slate-900">
            <div class="mb-3 text-[#2563EB] text-xl"><i class="fa-solid fa-screwdriver-wrench"></i></div>
            <div class="font-bold text-base mb-2">Network And Site Modernization</div>
            <p class="text-xs leading-6 text-slate-500">We specialize in supporting customers as they transition from aging, high-maintenance hardware to next-generation technology. Our teams manage and execute the safe decommissioning of legacy systems, and supervise the transition to modern infrastructure sustainably and with minimal network downtime.</p>
          </article>

          <article class="stagger-item glass-card rounded-2xl p-6 text-center text-slate-900">
            <div class="mb-3 text-[#0A5C3B] text-xl"><i class="fa-solid fa-recycle"></i></div>
            <div class="font-bold text-base mb-2">Legacy Asset Harvesting</div>
            <p class="text-xs leading-6 text-slate-500">As a core part of our modernization support, we manage the systematic recovery of decommissioned assets. Our team utilizes advanced, AI-based applications to rapidly identify, catalog, and assess the condition of legacy equipment on-site. This intelligence-driven approach minimizes errors, speeds up technical dismantling, and allows operators to cut costs through precise asset reuse or responsible recycling.</p>
          </article>

          <article class="stagger-item glass-card rounded-2xl p-6 text-center text-slate-900">
            <div class="mb-3 text-[#2563EB] text-xl"><i class="fa-solid fa-chart-line"></i></div>
            <div class="font-bold text-base mb-2">Radio Network Planning</div>
            <p class="text-xs leading-6 text-slate-500">We provide expert engineering services to ensure your radio access network (RAN) meets strict industry standards. Through advanced technologies performance evaluations, and continuous network optimization, we help operators eliminate coverage gaps and ensure seamless connectivity.</p>
          </article>

          <article class="stagger-item glass-card rounded-2xl p-6 text-center text-slate-900">
            <div class="mb-3 text-[#0A5C3B] text-xl"><i class="fa-solid fa-clipboard-list"></i></div>
            <div class="font-bold text-base mb-2">Network Site Technical Audits</div>
            <p class="text-xs leading-6 text-slate-500">We conduct independent, deep-dive technical audits to give operators complete visibility into their physical infrastructure. Leveraging our team's capability in using specialized AI applications, we automate and enhance the analysis of site imagery, structural data, and equipment configurations. This results in faster turnaround times, highly accurate safety and compliance reporting, and actionable insights to optimize site longevity.</p>
          </article>
        </div>
      </div>
    </section>

    <section id="projects" class="py-20 md:py-28">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-12 reveal-up">
          <h2 class="headline text-4xl sm:text-5xl font-extrabold brand-text">Our Projects</h2>
        </div>

        <div id="projects-splide" class="splide reveal-up" aria-label="Our Projects">
          <div class="splide__track">
            <ul class="splide__list">
              <li class="splide__slide">
                <article class="project-card rounded-3xl p-4 border border-[rgba(10,92,59,0.10)] bg-[linear-gradient(135deg,rgba(255,255,255,0.9),rgba(238,248,243,0.74),rgba(234,246,255,0.52))] shadow-[0_16px_34px_rgba(10,92,59,0.06)] flex flex-col h-full">
                  <img src="{{ asset('assets/images/assets.png') }}" alt="Assets Management" class="w-full h-52 object-cover rounded-2xl mb-4" />
                  <div class="flex flex-col flex-1 text-center px-2">
                    <h3 class="text-sm font-black uppercase text-[#0A5C3B] tracking-wide border-b border-[#0A5C3B]/20 pb-2 mb-3">Assets Management</h3>
                    <p class="text-gray-600 text-xs leading-relaxed flex-1">Managed asset visibility and field documentation for active telecom infrastructure projects.</p>
                    <a href="#contact" class="mt-4 inline-block w-full py-2.5 rounded-full bg-[linear-gradient(135deg,#0A5C3B,#2563EB)] text-white text-xs font-bold uppercase tracking-widest hover:opacity-95 transition-colors">Learn More</a>
                  </div>
                </article>
              </li>

              <li class="splide__slide">
                <article class="project-card rounded-3xl p-4 border border-[rgba(10,92,59,0.10)] bg-[linear-gradient(135deg,rgba(255,255,255,0.9),rgba(238,248,243,0.74),rgba(234,246,255,0.52))] shadow-[0_16px_34px_rgba(10,92,59,0.06)] flex flex-col h-full">
                  <img src="{{ asset('assets/images/telcovantage-teardown.png') }}" alt="Asset Recovery" class="w-full h-52 object-cover rounded-2xl mb-4" />
                  <div class="flex flex-col flex-1 text-center px-2">
                    <h3 class="text-sm font-black uppercase text-[#0A5C3B] tracking-wide border-b border-[#0A5C3B]/20 pb-2 mb-3">Asset Recovery</h3>
                    <p class="text-gray-600 text-xs leading-relaxed flex-1">Safe teardown, dismantling, and recovery of retired telecom systems and network assets.</p>
                    <a href="#contact" class="mt-4 inline-block w-full py-2.5 rounded-full bg-[linear-gradient(135deg,#0A5C3B,#2563EB)] text-white text-xs font-bold uppercase tracking-widest hover:opacity-95 transition-colors">Learn More</a>
                  </div>
                </article>
              </li>

              <li class="splide__slide">
                <article class="project-card rounded-3xl p-4 border border-[rgba(37,99,235,0.10)] bg-[linear-gradient(135deg,rgba(255,255,255,0.9),rgba(236,248,255,0.78),rgba(238,248,243,0.46))] shadow-[0_16px_34px_rgba(37,99,235,0.05)] flex flex-col h-full">
                  <img src="{{ asset('assets/images/granule-cable.png') }}" alt="Granule Cable" class="w-full h-52 object-cover rounded-2xl mb-4" />
                  <div class="flex flex-col flex-1 text-center px-2">
                    <h3 class="text-sm font-black uppercase text-[#2563EB] tracking-wide border-b border-[#2563EB]/20 pb-2 mb-3">Asset Processing</h3>
                    <p class="text-gray-600 text-xs leading-relaxed flex-1">Field deployment support and structured cable rollout management across project sites.</p>
                    <a href="#contact" class="mt-4 inline-block w-full py-2.5 rounded-full bg-[linear-gradient(135deg,#2563EB,#0A5C3B)] text-white text-xs font-bold uppercase tracking-widest hover:opacity-95 transition-colors">Learn More</a>
                  </div>
                </article>
              </li>

              <li class="splide__slide">
                <article class="project-card rounded-3xl p-4 border border-[rgba(10,92,59,0.10)] bg-[linear-gradient(135deg,rgba(255,255,255,0.9),rgba(238,248,243,0.74),rgba(234,246,255,0.52))] shadow-[0_16px_34px_rgba(10,92,59,0.06)] flex flex-col h-full">
                  <img src="{{ asset('assets/images/hole-survey.png') }}" alt="Pole Hole Survey" class="w-full h-52 object-cover rounded-2xl mb-4" />
                  <div class="flex flex-col flex-1 text-center px-2">
                    <h3 class="text-sm font-black uppercase text-[#0A5C3B] tracking-wide border-b border-[#0A5C3B]/20 pb-2 mb-3">Assets Audits</h3>
                    <p class="text-gray-600 text-xs leading-relaxed flex-1">Survey coordination and site readiness validation for smoother infrastructure deployment.</p>
                    <a href="#contact" class="mt-4 inline-block w-full py-2.5 rounded-full bg-[linear-gradient(135deg,#0A5C3B,#2563EB)] text-white text-xs font-bold uppercase tracking-widest hover:opacity-95 transition-colors">Learn More</a>
                  </div>
                </article>
              </li>

              <li class="splide__slide">
                <article class="project-card rounded-3xl p-4 border border-[rgba(37,99,235,0.10)] bg-[linear-gradient(135deg,rgba(255,255,255,0.9),rgba(236,248,255,0.78),rgba(238,248,243,0.46))] shadow-[0_16px_34px_rgba(37,99,235,0.05)] flex flex-col h-full">
                  <img src="{{ asset('assets/images/pole-lineman.jpeg') }}" alt="Field Operations" class="w-full h-52 object-cover rounded-2xl mb-4" />
                  <div class="flex flex-col flex-1 text-center px-2">
                    <h3 class="text-sm font-black uppercase text-[#2563EB] tracking-wide border-b border-[#2563EB]/20 pb-2 mb-3">Field Operations</h3>
                    <p class="text-gray-600 text-xs leading-relaxed flex-1">Real-time coordination of linemen, crews, and network installation work on site.</p>
                    <a href="#contact" class="mt-4 inline-block w-full py-2.5 rounded-full bg-[linear-gradient(135deg,#2563EB,#0A5C3B)] text-white text-xs font-bold uppercase tracking-widest hover:opacity-95 transition-colors">Learn More</a>
                  </div>
                </article>
              </li>

              <li class="splide__slide">
                <article class="project-card rounded-3xl p-4 border border-[rgba(37,99,235,0.10)] bg-[linear-gradient(135deg,rgba(255,255,255,0.9),rgba(236,248,255,0.78),rgba(238,248,243,0.46))] shadow-[0_16px_34px_rgba(37,99,235,0.05)] flex flex-col h-full">
                  <img src="{{ asset('assets/images/machine.jpeg') }}" alt="Heavy Equipment Support" class="w-full h-52 object-cover rounded-2xl mb-4" />
                  <div class="flex flex-col flex-1 text-center px-2">
                    <h3 class="text-sm font-black uppercase text-[#2563EB] tracking-wide border-b border-[#2563EB]/20 pb-2 mb-3">Heavy Equipment Support</h3>
                    <p class="text-gray-600 text-xs leading-relaxed flex-1">Operational support for field machinery and specialized deployment equipment in telecom builds.</p>
                    <a href="#contact" class="mt-4 inline-block w-full py-2.5 rounded-full bg-[linear-gradient(135deg,#2563EB,#0A5C3B)] text-white text-xs font-bold uppercase tracking-widest hover:opacity-95 transition-colors">Learn More</a>
                  </div>
                </article>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </section>

    <section id="contact" class="py-24 sm:py-28 bg-transparent">
      <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="grid gap-8 lg:grid-cols-[0.9fr_1.1fr]">
          <div class="contact-card reveal-left rounded-[2rem] border border-[rgba(10,92,59,0.10)] bg-[linear-gradient(135deg,rgba(255,255,255,0.84),rgba(236,248,242,0.78),rgba(234,246,255,0.62))] p-8 shadow-[0_18px_40px_rgba(10,92,59,0.08)] backdrop-blur-xl">
            <p class="text-xs font-semibold uppercase tracking-[0.32em] text-[#0A5C3B]/70">Get In Touch</p>
            <h2 class="headline mt-4 text-4xl font-extrabold brand-text">Contact Us</h2>
            <p class="mt-5 max-w-md text-sm leading-8 text-slate-600">
              Ready to transform your telecommunications infrastructure? Reach out and let’s build a stronger, more connected operation.
            </p>

            <div class="mt-10 space-y-6 text-sm text-slate-700">
              <div class="flex items-start gap-4">
                <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-[linear-gradient(135deg,rgba(10,92,59,0.10),rgba(59,181,138,0.12))] text-[#0A5C3B] shadow-sm">
                  <i class="fa-solid fa-location-dot"></i>
                </div>
                <div>
                  <p class="text-xs uppercase tracking-[0.22em] text-slate-400">Address</p>
                  <p class="mt-1">1811 Park Triangle Corporate Plaza 32nd Street, BGC, Taguig City</p>
                </div>
              </div>

              <div class="flex items-start gap-4">
                <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-[linear-gradient(135deg,rgba(37,99,235,0.10),rgba(234,246,255,0.8))] text-[#2563EB] shadow-sm">
                  <i class="fa-solid fa-phone"></i>
                </div>
                <div>
                  <p class="text-xs uppercase tracking-[0.22em] text-slate-400">Phone</p>
                  <p class="mt-1">+63 917 859 6023</p>
                </div>
              </div>

              <div class="flex items-start gap-4">
                <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-[linear-gradient(135deg,rgba(10,92,59,0.10),rgba(234,246,255,0.72))] text-[#0A5C3B] shadow-sm">
                  <i class="fa-solid fa-envelope"></i>
                </div>
                <div>
                  <p class="text-xs uppercase tracking-[0.22em] text-slate-400">Email</p>
                  <p class="mt-1">info@telcovantage.com</p>
                </div>
              </div>
            </div>
          </div>

          <div class="contact-card reveal-right rounded-[2rem] border border-[rgba(37,99,235,0.10)] bg-[linear-gradient(135deg,rgba(255,255,255,0.84),rgba(236,248,242,0.66),rgba(234,246,255,0.74))] p-8 shadow-[0_18px_40px_rgba(37,99,235,0.06)] backdrop-blur-xl">
            <form id="contact-form" action="{{ route('contact.send') }}" method="POST" class="space-y-5">
              @csrf

              <div class="grid gap-5 sm:grid-cols-2">
                <div>
                  <label class="mb-2 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">First Name</label>
                  <input name="first_name" type="text" placeholder="Juan" required class="w-full rounded-2xl border border-[rgba(10,92,59,0.12)] bg-white/80 px-4 py-3 text-slate-900 placeholder:text-slate-400 focus:border-[#0A5C3B]/40 focus:outline-none" />
                </div>
                <div>
                  <label class="mb-2 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Last Name</label>
                  <input name="last_name" type="text" placeholder="Dela Cruz" required class="w-full rounded-2xl border border-[rgba(37,99,235,0.12)] bg-white/80 px-4 py-3 text-slate-900 placeholder:text-slate-400 focus:border-[#2563EB]/40 focus:outline-none" />
                </div>
              </div>

              <div>
                <label class="mb-2 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Email</label>
                <input name="email" type="email" placeholder="juan@company.com" required class="w-full rounded-2xl border border-[rgba(10,92,59,0.12)] bg-white/80 px-4 py-3 text-slate-900 placeholder:text-slate-400 focus:border-[#0A5C3B]/40 focus:outline-none" />
              </div>

              <div>
                <label class="mb-2 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Company</label>
                <input name="company" type="text" placeholder="Your Company Name" class="w-full rounded-2xl border border-[rgba(37,99,235,0.12)] bg-white/80 px-4 py-3 text-slate-900 placeholder:text-slate-400 focus:border-[#2563EB]/40 focus:outline-none" />
              </div>

              <div>
                <label class="mb-2 block text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Message</label>
                <textarea name="message" rows="5" placeholder="Tell us about your project or inquiry..." required class="w-full rounded-2xl border border-[rgba(10,92,59,0.12)] bg-white/80 px-4 py-3 text-slate-900 placeholder:text-slate-400 focus:border-[#0A5C3B]/40 focus:outline-none"></textarea>
              </div>

              <p id="form-status" class="hidden rounded-2xl px-4 py-3 text-sm"></p>

              <button id="submit-btn" type="submit" class="w-full rounded-2xl bg-[linear-gradient(135deg,#0A5C3B,#2563EB)] px-6 py-3.5 text-sm font-bold text-white transition hover:opacity-95">
                Send Message
              </button>
            </form>
          </div>
        </div>
      </div>
    </section>
  </main>

  <footer class="bg-transparent border-t border-gray-200/70 pt-14 pb-8">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8 mb-12 stagger-group">
        <div class="stagger-item lg:col-span-2">
          <img
            src="{{ asset('assets/images/logo-dark.png') }}"
            alt="TelcoVantage Philippines"
            class="h-10 w-auto mb-4 object-contain"
            onerror="this.style.display='none'; this.nextElementSibling.style.display='block';"
          />
          <p class="hidden text-lg font-black tracking-widest mb-4 brand-text">TELCOVANTAGE</p>
          <p class="text-gray-500 text-sm leading-relaxed max-w-xs">
            The most trusted and innovative telecom service partner, empowering businesses with seamless,
            efficient, and sustainable connectivity solutions.
          </p>
        </div>

        <div class="stagger-item">
          <h4 class="text-xs font-bold tracking-[0.2em] uppercase text-gray-500 mb-4">Quick Links</h4>
          <ul class="space-y-2.5 text-sm text-gray-700">
            <li><a href="#home" class="hover:text-[#0A5C3B] transition-colors">Home</a></li>
            <li><a href="#about" class="hover:text-[#0A5C3B] transition-colors">About Us</a></li>
            <li><a href="#services" class="hover:text-[#0A5C3B] transition-colors">Services</a></li>
            <li><a href="#projects" class="hover:text-[#0A5C3B] transition-colors">Our Projects</a></li>
            <li><a href="#contact" class="hover:text-[#0A5C3B] transition-colors">Contact Us</a></li>
          </ul>
        </div>

        <div class="stagger-item">
          <h4 class="text-xs font-bold tracking-[0.2em] uppercase text-gray-500 mb-4">Services</h4>
          <ul class="space-y-2.5 text-sm text-gray-700">
            <li>Connectivity Solutions</li>
            <li>Infrastructure Management</li>
            <li>Digital Innovation</li>
            <li>Network Security</li>
            <li>Managed Services</li>
          </ul>
        </div>
      </div>

      <div class="border-t border-gray-200 pt-6 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs text-gray-500">
        <p>&copy; 2026 TelcoVantage Philippines. All rights reserved.</p>
        <p>Empowering businesses through connectivity.</p>
      </div>
    </div>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/@splidejs/splide@4.1.4/dist/js/splide.min.js"></script>

  <script>
    gsap.registerPlugin(ScrollTrigger);

    const header = document.getElementById('site-header');
    const menuBtn = document.getElementById('menu-btn');
    const menuOpenIcon = document.getElementById('menu-open');
    const menuCloseIcon = document.getElementById('menu-close');
    const mobileMenu = document.getElementById('mobile-menu');
    let menuOpen = false;

    window.addEventListener('scroll', () => {
      if (window.scrollY > 24) {
        header.classList.add('nav-scrolled');
      } else {
        header.classList.remove('nav-scrolled');
      }
    });

    menuBtn.addEventListener('click', () => {
      menuOpen = !menuOpen;

      gsap.to(mobileMenu, {
        height: menuOpen ? 'auto' : 0,
        opacity: menuOpen ? 1 : 0,
        duration: 0.35,
        ease: 'power2.out'
      });

      menuOpenIcon.classList.toggle('hidden', menuOpen);
      menuCloseIcon.classList.toggle('hidden', !menuOpen);
    });

    mobileMenu.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', () => {
        menuOpen = false;
        gsap.to(mobileMenu, {
          height: 0,
          opacity: 0,
          duration: 0.3,
          ease: 'power2.out'
        });
        menuOpenIcon.classList.remove('hidden');
        menuCloseIcon.classList.add('hidden');
      });
    });

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (!prefersReducedMotion) {
      gsap.from('.nav-shell', {
        y: -18,
        opacity: 0,
        duration: 0.8,
        ease: 'power3.out'
      });

      gsap.from('.hero-copy > *', {
        y: 28,
        opacity: 0,
        stagger: 0.12,
        duration: 0.9,
        ease: 'power3.out',
        delay: 0.15
      });

      gsap.from('.hero-visual', {
        x: 40,
        opacity: 0,
        duration: 1,
        ease: 'power3.out',
        delay: 0.2
      });

      const heroImg = document.getElementById('hero-img');
      if (heroImg) {
        gsap.to(heroImg, {
          y: -12,
          duration: 2.8,
          repeat: -1,
          yoyo: true,
          ease: 'sine.inOut',
          overwrite: 'auto'
        });
      }

      gsap.utils.toArray('.reveal-up').forEach((el) => {
        gsap.from(el, {
          y: 30,
          opacity: 0,
          duration: 0.9,
          ease: 'power3.out',
          scrollTrigger: {
            trigger: el,
            start: 'top 88%',
            once: true
          }
        });
      });

      gsap.utils.toArray('.reveal-left').forEach((el) => {
        gsap.from(el, {
          x: -50,
          opacity: 0,
          duration: 1,
          ease: 'power3.out',
          scrollTrigger: {
            trigger: el,
            start: 'top 88%',
            once: true
          }
        });
      });

      gsap.utils.toArray('.reveal-right').forEach((el) => {
        gsap.from(el, {
          x: 50,
          opacity: 0,
          duration: 1,
          ease: 'power3.out',
          scrollTrigger: {
            trigger: el,
            start: 'top 88%',
            once: true
          }
        });
      });

      gsap.utils.toArray('.stagger-group').forEach((group) => {
        const items = group.querySelectorAll('.stagger-item');
        if (!items.length) return;

        gsap.from(items, {
          y: 28,
          opacity: 0,
          duration: 0.85,
          stagger: 0.12,
          ease: 'power3.out',
          scrollTrigger: {
            trigger: group,
            start: 'top 86%',
            once: true
          }
        });
      });
    }

    new Splide('#projects-splide', {
      type: 'loop',
      perPage: 3,
      perMove: 1,
      gap: '1.5rem',
      pagination: true,
      arrows: true,
      breakpoints: {
        1280: { perPage: 3 },
        1024: { perPage: 2 },
        640: { perPage: 1 }
      }
    }).mount();

    const contactForm = document.getElementById('contact-form');
    const submitBtn = document.getElementById('submit-btn');
    const formStatus = document.getElementById('form-status');

    if (contactForm) {
      contactForm.addEventListener('submit', async function (e) {
        e.preventDefault();

        // Loading state
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span style="display:inline-flex;align-items:center;gap:10px;justify-content:center"><svg style="animation:spin 0.8s linear infinite;width:18px;height:18px;flex-shrink:0" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="rgba(255,255,255,0.35)" stroke-width="3"/><path d="M12 2a10 10 0 0 1 10 10" stroke="white" stroke-width="3" stroke-linecap="round"/></svg>Sending…</span>';
        formStatus.className = 'hidden';

        try {
          const response = await fetch(contactForm.action, {
            method: 'POST',
            headers: {
              'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
              'Accept': 'application/json'
            },
            body: new FormData(contactForm)
          });

          const data = await response.json();

          if (!response.ok) {
            throw new Error(data.message || 'Something went wrong.');
          }

          // Success — change button, keep disabled
          submitBtn.innerHTML = '<span style="display:inline-flex;align-items:center;gap:8px;justify-content:center"><svg style="width:18px;height:18px;flex-shrink:0" viewBox="0 0 24 24" fill="none"><path d="M5 13l4 4L19 7" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>Email Successfully Sent — Thank you!</span>';
          submitBtn.style.background = 'linear-gradient(135deg,#059669,#0A5C3B)';
          contactForm.reset();

        } catch (error) {
          // Error — show error message, restore button
          formStatus.textContent = error.message || 'Unable to send your message. Please try again.';
          formStatus.className = 'rounded-2xl px-4 py-3 text-sm bg-red-50 text-red-700 border border-red-200';
          submitBtn.disabled = false;
          submitBtn.innerHTML = 'Send Message';
          submitBtn.style.background = '';
        }
      });
    }
  </script>
</body>
</html>
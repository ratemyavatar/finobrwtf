<script src="/s/admin/jquery.min.js"></script>
<script src="/s/blog/casper.js"></script>
@if($kind === 'post')<script>
    $(document).ready(function () {
        // FitVids - start
        var $postContent = $(".post-full-content");
        $postContent.fitVids();
        // FitVids - end

        // Replace nav with title on scroll - start
        Casper.stickyNavTitle({
            navSelector: '.site-nav-main',
            titleSelector: '.post-full-title',
            activeClass: 'nav-post-title-active'
        });
        // Replace nav with title on scroll - end

        // Hover on avatar
        var hoverTimeout;
        $('.author-list-item').hover(function () {
            var $this = $(this);

            clearTimeout(hoverTimeout);

            $('.author-card').removeClass('hovered');
            $(this).children('.author-card').addClass('hovered');

        }, function () {
            var $this = $(this);

            hoverTimeout = setTimeout(function () {
                $this.children('.author-card').removeClass('hovered');
            }, 800);
        });
    });
</script>@elseif($kind === 'home')<script>

    // NOTE: Scroll performance is poor in Safari
    // - this appears to be due to the events firing much more slowly in Safari.
    //   Dropping the scroll event and using only a raf loop results in smoother
    //   scrolling but continuous processing even when not scrolling
    $(document).ready(function () {
        
        var nav = document.querySelector('.site-nav-main .site-nav');
        var feed = document.querySelector('.post-feed');

        var lastScrollY = window.scrollY;
        var lastWindowHeight = window.innerHeight;
        var lastDocumentHeight = $(document).height();
        var ticking = false;

        function onScroll() {
            lastScrollY = window.scrollY;
            requestTick();
        }

        function onResize() {
            lastWindowHeight = window.innerHeight;
            lastDocumentHeight = $(document).height();
            requestTick();
        }

        function requestTick() {
            if (!ticking) {
                requestAnimationFrame(update);
            }
            ticking = true;
        }

        function update() {
            var trigger = feed.getBoundingClientRect().top + window.scrollY;
            var progressMax = lastDocumentHeight - lastWindowHeight;

            // show/hide nav
            if (lastScrollY >= trigger - 20) {
                nav.classList.add('fixed-nav-active');
            } else {
                nav.classList.remove('fixed-nav-active');
            }

            ticking = false;
        }

        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onResize, false);

        update();

    });
</script>@endif

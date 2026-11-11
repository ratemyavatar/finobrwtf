<script src="/s/admin/jquery.min.js"></script>
<script src="/s/blog/casper.js"></script>
@if($kind === 'post')<script>
    $(document).ready(function () {

        var $postContent = $(".post-full-content");
        $postContent.fitVids();

        Casper.stickyNavTitle({
            navSelector: '.site-nav-main',
            titleSelector: '.post-full-title',
            activeClass: 'nav-post-title-active'
        });

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

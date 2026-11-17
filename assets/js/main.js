

document.addEventListener('DOMContentLoaded', () => {

  
  if (typeof lucide !== 'undefined') {
    lucide.createIcons();
  }

  
  const navbar = document.getElementById('mainNavbar');
  if (navbar) {
    const onScroll = () => navbar.classList.toggle('scrolled', window.scrollY > 10);
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  
  const avatarBtn = document.querySelector('.avatar-btn');
  const avatarDropdown = document.querySelector('.avatar-dropdown');
  if (avatarBtn && avatarDropdown) {
    avatarBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      avatarDropdown.classList.toggle('open');
    });
    document.addEventListener('click', () => {
      avatarDropdown.classList.remove('open');
    });
    avatarDropdown.addEventListener('click', (e) => e.stopPropagation());
  }

  
  const searchInput = document.querySelector('.search-input');
  const searchWrap  = document.querySelector('.search-wrap');
  if (searchInput && searchWrap) {
    searchInput.addEventListener('focus',  () => searchWrap.classList.add('focused'));
    searchInput.addEventListener('blur',   () => searchWrap.classList.remove('focused'));
  }

  
  document.querySelectorAll('.banner-close').forEach(btn => {
    btn.addEventListener('click', () => {
      const banner = btn.closest('.banner-teal, .banner-amber');
      if (banner) {
        banner.style.transition = 'opacity 0.2s, max-height 0.3s, margin 0.3s, padding 0.3s';
        banner.style.opacity = '0';
        banner.style.maxHeight = '0';
        banner.style.marginBottom = '0';
        banner.style.padding = '0';
        banner.style.overflow = 'hidden';
        setTimeout(() => banner.remove(), 300);
      }
    });
  });

  
  const tabs = document.querySelectorAll('.tab[data-filter]');
  const repairCards = document.querySelectorAll('.repair-card[data-status]');

  if (tabs.length && repairCards.length) {
    tabs.forEach(tab => {
      tab.addEventListener('click', () => {
        tabs.forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        const filter = tab.dataset.filter;
        repairCards.forEach(card => {
          const show = filter === 'all' || card.dataset.status === filter;
          card.style.display = show ? '' : 'none';
        });
      });
    });
  }

  

  
  if (typeof gsap !== 'undefined') {
    const animTargets = document.querySelectorAll(
      '.card, .product-card, .repair-card, .notif-item, .stat-card'
    );
    if (animTargets.length) {
      gsap.from(animTargets, {
        y: 20,
        opacity: 0,
        duration: 0.5,
        stagger: 0.06,
        ease: 'power2.out',
        clearProps: 'all'
      });
    }

    
    document.querySelectorAll('.stat-num[data-target]').forEach(el => {
      const target = parseInt(el.dataset.target, 10) || 0;
      const obj    = { val: 0 };
      gsap.to(obj, {
        val: target,
        duration: 1.1,
        ease: 'power2.out',
        onUpdate() { el.textContent = Math.round(obj.val); }
      });
    });
  }

  
  document.querySelectorAll('.upload-zone').forEach(zone => {
    zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
    zone.addEventListener('drop',      e => { e.preventDefault(); zone.classList.remove('drag-over'); });
  });

  
  const imgInput    = document.getElementById('applianceImage');
  const previewWrap = document.getElementById('uploadPreview');
  const previewImg  = document.getElementById('previewImg');
  const removeBtn   = document.getElementById('removePreview');
  const uploadZone  = document.querySelector('.upload-zone');

  if (imgInput && previewWrap && previewImg) {
    imgInput.addEventListener('change', () => {
      const file = imgInput.files[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = e => {
          previewImg.src = e.target.result;
          previewWrap.style.display = 'block';
          if (uploadZone) uploadZone.style.display = 'none';
        };
        reader.readAsDataURL(file);
      }
    });
    if (removeBtn) {
      removeBtn.addEventListener('click', () => {
        imgInput.value = '';
        previewImg.src = '';
        previewWrap.style.display = 'none';
        if (uploadZone) uploadZone.style.display = '';
      });
    }
  }

  
  const sidebarToggle = document.getElementById('sidebarCollapse');
  const sidebar = document.getElementById('sidebar');
  
  if (sidebarToggle && sidebar) {
    
    if (localStorage.getItem('sidebar-collapsed') === 'true') {
      sidebar.classList.add('collapsed');
      document.body.classList.add('sidebar-collapsed');
      const icon = sidebarToggle.querySelector('i');
      if (icon) {
        icon.classList.remove('fa-bars');
        icon.classList.add('fa-indent');
      }
    }

    sidebarToggle.addEventListener('click', () => {
      sidebar.classList.toggle('collapsed');
      const isCollapsed = sidebar.classList.contains('collapsed');
      document.body.classList.toggle('sidebar-collapsed', isCollapsed);
      localStorage.setItem('sidebar-collapsed', isCollapsed);
      
      
      const icon = sidebarToggle.querySelector('i');
      if (icon) {
        if (isCollapsed) {
          icon.classList.remove('fa-bars');
          icon.classList.add('fa-indent');
        } else {
          icon.classList.remove('fa-indent');
          icon.classList.add('fa-bars');
        }
      }
    });
  }

  
  const submenuToggles = document.querySelectorAll('.submenu-toggle');
  submenuToggles.forEach(toggle => {
    toggle.addEventListener('click', (e) => {
      const sidebar = document.querySelector('.sidebar');
      
      if (sidebar && sidebar.classList.contains('collapsed')) {
        const collapseBtn = document.getElementById('sidebarCollapse');
        if (collapseBtn) collapseBtn.click();
        return;
      }
      
      e.preventDefault();
      const parent = toggle.closest('.nav-item');
      const isOpen = parent.classList.contains('open');
      
      
      document.querySelectorAll('.nav-item.has-submenu.open').forEach(item => {
        if (item !== parent) item.classList.remove('open');
      });
      
      parent.classList.toggle('open', !isOpen);
    });
  });

  
  const processSmsQueue = async () => {
    try {
      if (typeof SITE_URL === 'undefined') return;
      const response = await fetch(`${SITE_URL}/api/process-sms-queue.php`);
      if (!response.ok) return;
      const data = await response.json();
      if (data.success && data.processed_count > 0) {
        console.log(`Dispatched ${data.processed_count} SMS messages in background.`);
      }
    } catch (err) {
      
    }
  };

  
  if (typeof USER_ROLE !== 'undefined' && USER_ROLE !== '') {
    setTimeout(processSmsQueue, 2000);
    setInterval(processSmsQueue, 25000);
  }
});
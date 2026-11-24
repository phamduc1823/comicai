<script setup lang="ts">
// BlueToon Footer Redesign
</script>

<template>
  <footer class="app-footer">
    <div class="footer-container">
      <!-- Khu vực trên: Thông tin liên hệ -->
      <div class="footer-contact-section">
        <div class="office-column">
          <h3 class="office-title">Vietnam Office</h3>
          <ul class="contact-list">
            <li class="contact-item">
              <span class="contact-icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
                </svg>
              </span>
              <span class="contact-label">Phone:</span>
              <a href="tel:+84868985928" class="contact-link">+(84) 868985928</a>
            </li>

            <li class="contact-item">
              <span class="contact-icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                  <polyline points="22,6 12,13 2,6"/>
                </svg>
              </span>
              <span class="contact-label">Mail:</span>
              <a href="mailto:bluetoon@gmail.com" class="contact-link">bluetoon@gmail.com</a>
            </li>

            <li class="contact-item">
              <span class="contact-icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                  <circle cx="12" cy="10" r="3"/>
                </svg>
              </span>
              <span class="contact-label">Address:</span>
              <span class="contact-text">18 Pham Hung</span>
            </li>
          </ul>
        </div>
      </div>

      <!-- Đường phân cách ngang -->
      <div class="footer-divider" role="separator"></div>

      <!-- Khu vực dưới: Copyright -->
      <div class="footer-bottom-section">
        <p class="copyright-text">Copyright © 2026 BlueToon. All rights reserved.</p>
      </div>
    </div>
  </footer>
</template>

<style scoped>
.app-footer {
  background: linear-gradient(180deg, rgba(17, 24, 39, 0.98) 0%, rgba(11, 15, 25, 1) 100%);
  border-top: 1px solid rgba(56, 189, 248, 0.15);
  padding: 44px 0 28px;
  margin-top: auto;
  font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
  color: var(--color-text-primary);
}

.footer-container {
  max-width: 1440px;
  margin: 0 auto;
  padding: 0 var(--spacing-xl);
  display: flex;
  flex-direction: column;
}

/* Khu vực trên: Thông tin liên hệ */
.footer-contact-section {
  display: flex;
  flex-direction: column;
}

.office-column {
  max-width: 480px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.office-title {
  font-size: 20px;
  font-weight: 700;
  color: #ffffff;
  letter-spacing: -0.01em;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 8px;
}

.office-title::before {
  content: '';
  display: inline-block;
  width: 4px;
  height: 18px;
  background: linear-gradient(180deg, var(--color-primary), #0284c7);
  border-radius: var(--radius-xs);
}

.contact-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.contact-item {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 15px;
  line-height: 1.5;
  color: var(--color-text-secondary);
}

.contact-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  color: var(--color-primary);
  flex-shrink: 0;
}

.contact-label {
  font-weight: 600;
  color: #cbd5e1;
  min-width: 62px;
}

.contact-link {
  color: #f1f5f9;
  text-decoration: none;
  transition: color var(--transition-fast);
  word-break: break-word;
}

.contact-link:hover {
  color: var(--color-primary);
  text-decoration: underline;
}

.contact-text {
  color: #f1f5f9;
  word-break: break-word;
}

/* Đường phân cách ngang */
.footer-divider {
  height: 1px;
  background: linear-gradient(
    90deg,
    rgba(56, 189, 248, 0.3) 0%,
    rgba(255, 255, 255, 0.12) 40%,
    rgba(255, 255, 255, 0.05) 100%
  );
  margin: 32px 0 20px;
  width: 100%;
}

/* Khu vực dưới: Copyright */
.footer-bottom-section {
  display: flex;
  align-items: center;
  justify-content: center;
  text-align: center;
}

.copyright-text {
  font-size: 14px;
  color: var(--color-text-muted);
  margin: 0;
  letter-spacing: 0.01em;
  text-align: center;
}

/* Responsive */
@media (max-width: 1100px) {
  .footer-container {
    padding: 0 var(--spacing-md);
  }
}

@media (max-width: 640px) {
  .app-footer {
    padding: 32px 0 20px;
  }

  .footer-container {
    padding: 0 var(--spacing-sm);
  }

  .office-column {
    max-width: 100%;
    gap: 14px;
  }

  .office-title {
    font-size: 18px;
  }

  .contact-item {
    font-size: 14px;
    gap: 8px;
    flex-wrap: wrap;
  }

  .contact-label {
    min-width: 56px;
  }

  .footer-divider {
    margin: 24px 0 16px;
  }

  .copyright-text {
    font-size: 13px;
  }
}
</style>

<?php if (is_logged_in()): ?>
        </main>
        <footer style="padding: 20px 30px; text-align: center; font-size: 0.75rem; color: var(--text-muted); border-top: 1px solid var(--border-glow); background: var(--bg-surface);">
            &copy; <?php echo date('Y'); ?> CyberKavach Club. Central OS Platform. All rights reserved.
        </footer>
    </div> <!-- /main-wrapper -->
</div> <!-- /app-container -->
<?php endif; ?>

<!-- Javascript Interactions -->
<script>
document.addEventListener("DOMContentLoaded", function () {
    // Mobile Sidebar Toggle
    const sidebarCollapse = document.getElementById("sidebarCollapse");
    const appSidebar = document.getElementById("appSidebar");
    
    if (sidebarCollapse && appSidebar) {
        sidebarCollapse.addEventListener("click", function (e) {
            e.stopPropagation();
            appSidebar.classList.toggle("active");
        });
        
        // Close sidebar if clicking outside of it on mobile
        document.addEventListener("click", function (e) {
            if (window.innerWidth <= 768) {
                if (!appSidebar.contains(e.target) && e.target !== sidebarCollapse) {
                    appSidebar.classList.remove("active");
                }
            }
        });
    }

    // Auto-fade flash alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function (alert) {
        setTimeout(function () {
            alert.style.transition = 'opacity 0.6s ease';
            alert.style.opacity = '0';
            setTimeout(function () {
                alert.remove();
            }, 600);
        }, 5000);
    });

    // Quiz interactive option buttons selection
    const optionButtons = document.querySelectorAll('.quiz-option-btn');
    optionButtons.forEach(btn => {
        btn.addEventListener('click', function () {
            const questionId = this.dataset.questionId;
            // Deselect other options for this question
            document.querySelectorAll(`.quiz-option-btn[data-question-id="${questionId}"]`).forEach(b => {
                b.classList.remove('selected');
            });
            // Select this option
            this.classList.add('selected');
            // Check the radio input
            const radioId = this.getAttribute('for');
            const radio = document.getElementById(radioId);
            if (radio) {
                radio.checked = true;
            }
        });
    });
});
</script>
</body>
</html>

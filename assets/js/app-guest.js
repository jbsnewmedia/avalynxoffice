document.addEventListener('DOMContentLoaded', function(){
    (function(){
        const getStoredTheme = () => localStorage.getItem('theme');
        const setStoredTheme = theme => localStorage.setItem('theme', theme);
        const getPreferredTheme = () => {
            const storedTheme = getStoredTheme();
            if (storedTheme) {
                return storedTheme;
            }
            return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        };
        const setTheme = theme => {
            if (theme === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                document.documentElement.setAttribute('data-bs-theme', 'dark');
            } else {
                document.documentElement.setAttribute('data-bs-theme', theme);
            }
        };
        const updateThemeIcon = (theme) => {
            const toggleBtn = document.getElementById('darkModeToggle');
            if (!toggleBtn) return;
            const icon = toggleBtn.querySelector('i');
            if (theme === 'dark') {
                icon.classList.remove('fa-moon');
                icon.classList.add('fa-sun');
            } else {
                icon.classList.remove('fa-sun');
                icon.classList.add('fa-moon');
            }
        };
        const initDarkMode = () => {
            const theme = getPreferredTheme();
            setTheme(theme);
            updateThemeIcon(theme);

            const toggleBtn = document.getElementById('darkModeToggle');
            if (toggleBtn) {
                toggleBtn.addEventListener('click', () => {
                    const currentTheme = document.documentElement.getAttribute('data-bs-theme');
                    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                    setStoredTheme(newTheme);
                    setTheme(newTheme);
                    updateThemeIcon(newTheme);
                });
            }
        };
        initDarkMode();
    })();
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js').catch(function(e){console.warn('SW reg failed', e);});
        });
    }
    var loginForm = document.querySelector('.needs-validation');
    if (loginForm) {
        loginForm.addEventListener('submit', function(event){
            if (!loginForm.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            loginForm.classList.add('was-validated');
        }, false);
    }
    var passwordInput = document.getElementById('inputPassword');
    var togglePasswordBtn = document.getElementById('togglePassword');
    if (togglePasswordBtn && passwordInput) {
        var labelShow = togglePasswordBtn.getAttribute('data-label-show') || 'Passwort anzeigen';
        var labelHide = togglePasswordBtn.getAttribute('data-label-hide') || 'Passwort verbergen';
        togglePasswordBtn.addEventListener('click', function(){
            var isPassword = passwordInput.getAttribute('type') === 'password';
            passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
            togglePasswordBtn.setAttribute('aria-label', isPassword ? labelHide : labelShow);
            if (isPassword) {
                togglePasswordBtn.classList.add('active');
            } else {
                togglePasswordBtn.classList.remove('active');
            }
            togglePasswordBtn.innerHTML = isPassword
                ? '<i class="fa-solid fa-eye-slash" aria-hidden="true"></i>'
                : '<i class="fa-solid fa-eye" aria-hidden="true"></i>';
        });
    }
});

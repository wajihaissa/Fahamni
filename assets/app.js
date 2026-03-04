import './bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * We recommend including the built version of this JavaScript file
 * (and its CSS file) in your base layout (base.html.twig).
 */

// any CSS you import will output into a single css file (app.css in this case)
// main.js - Contrôle centralisé : thème (dark/light), menu mobile, page active
// Clé localStorage pour le thème : 'theme' (valeurs 'light' | 'dark'). Utiliser la même clé partout.
// S'exécute au chargement ou sur DOMContentLoaded (script chargé en module = deferred, DOMContentLoaded peut être déjà passé).
function initFront() {
    // Thème light/dark : géré uniquement par le script inline dans base_front.html.twig (une seule source, pas de double écouteur)
        
        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const nav = document.querySelector('nav');
        
        if (mobileMenuBtn && nav) {
            mobileMenuBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                nav.classList.toggle('active');
            });
            
            // Close mobile menu when clicking outside
            document.addEventListener('click', (event) => {
                if (!nav.contains(event.target) && !mobileMenuBtn.contains(event.target)) {
                    nav.classList.remove('active');
                }
            });
            
            // Close mobile menu when clicking on a link
            nav.querySelectorAll('a').forEach(link => {
                link.addEventListener('click', () => {
                    nav.classList.remove('active');
                });
            });
        }
        
        // Active page detection
        function setActivePage() {
            const currentPath = window.location.pathname;
            const navLinks = document.querySelectorAll('.nav-link');
            
            navLinks.forEach(link => {
                link.classList.remove('active');
                
                // Get the expected path for this link
                const linkPath = link.getAttribute('href');
                
                // Check if current path matches link path
                if (currentPath === linkPath) {
                    link.classList.add('active');
                }
                
                // Special case for home page
                if (currentPath === '/' && linkPath === '/') {
                    link.classList.add('active');
                }
                
                // Handle subpaths (e.g., /calendar/anything should highlight calendar)
                if (linkPath !== '/' && currentPath.startsWith(linkPath) && linkPath !== '/') {
                    link.classList.add('active');
                }
            });
            
            // Fallback: If no link is active and we're not on home, highlight based on URL pattern
            const activeLinks = document.querySelectorAll('.nav-link.active');
            if (activeLinks.length === 0 && currentPath !== '/') {
                // Try to guess the active page from URL
                const pathParts = currentPath.split('/').filter(part => part.length > 0);
                if (pathParts.length > 0) {
                    const firstPath = pathParts[0];
                    const possibleLink = document.querySelector(`.nav-link[data-page="${firstPath}"]`);
                    if (possibleLink) {
                        possibleLink.classList.add('active');
                    }
                }
            }
        }
        
    // Set active page on load
    setActivePage();

    // Update active page when navigating (for SPA-like behavior)
    document.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', function() {
            document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
            this.classList.add('active');
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initFront);
} else {
    initFront();
}
import './styles/app.css';

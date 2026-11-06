import { createApp } from 'vue';
import App from './App.vue';
import './style.css';
try {
  createApp(App).mount('#stammbaum-app');
} catch {
  const root = document.getElementById('stammbaum-app');
  root.textContent = 'Stammbaum could not start. Reload the page to try again.';
  root.setAttribute('role', 'alert');
}

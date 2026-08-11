import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { App } from './App';
import './styles/global.css';

const container = document.getElementById('root');
if (!container) throw new Error('Root element #root not found.');

createRoot(container).render(
  <StrictMode>
    {/* basename tracks Vite's `base`, so the app also works when served from a
        sub-path such as sites.gsl/firehouse (creative brief §8). */}
    <BrowserRouter basename={import.meta.env.BASE_URL}>
      <App />
    </BrowserRouter>
  </StrictMode>,
);

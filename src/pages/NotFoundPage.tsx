import { Button } from '../design-system';
import './NotFoundPage.css';

export function NotFoundPage() {
  return (
    <div className="fh-not-found fh-container">
      <span className="fh-eyebrow">Error 404</span>
      <h1 className="fh-not-found__heading">Page not found</h1>
      <p className="fh-not-found__body">
        That page doesn&rsquo;t exist, or it may have moved. The project explorer and
        topic summaries are still where you left them.
      </p>
      <div className="fh-not-found__actions">
        <Button variant="primary" to="/" iconRight="arrow-right">
          Back to The Firehouse
        </Button>
        <Button variant="secondary" to="/projects">
          Explore projects
        </Button>
      </div>
    </div>
  );
}

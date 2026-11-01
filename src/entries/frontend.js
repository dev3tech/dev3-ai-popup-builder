/**
 * Front-end bundle (no WordPress script dependencies). Loaded deferred, only on pages
 * where a campaign can show.
 */
import '../frontend/popup.css';
import { boot } from '../frontend/runtime';

boot( window );

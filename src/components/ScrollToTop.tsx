import { useEffect } from "react";
import { useLocation } from "react-router-dom";

/**
 * ScrollToTop Component
 * Ensures that whenever a user navigates between pages or route tabs,
 * the window and document scroll position immediately resets to the very top (0, 0),
 * preventing the new page from opening near the footer or bottom of the screen.
 */
export const ScrollToTop = () => {
  const { pathname, search } = useLocation();

  useEffect(() => {
    // Immediate instant reset of all scroll containers
    window.scrollTo({
      top: 0,
      left: 0,
      behavior: "instant" as ScrollBehavior,
    });

    if (document.documentElement) {
      document.documentElement.scrollTop = 0;
    }
    if (document.body) {
      document.body.scrollTop = 0;
    }

    // Secondary micro-task scroll reset to guarantee top position even after DOM render
    requestAnimationFrame(() => {
      window.scrollTo({
        top: 0,
        left: 0,
        behavior: "instant" as ScrollBehavior,
      });
      if (document.documentElement) document.documentElement.scrollTop = 0;
      if (document.body) document.body.scrollTop = 0;
    });
  }, [pathname, search]);

  return null;
};

export default ScrollToTop;

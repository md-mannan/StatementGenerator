import { ArrowUp } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

const SCROLL_OFFSET = 300;

export function BackToTopButton() {
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        function handleScroll() {
            setVisible(window.scrollY > SCROLL_OFFSET);
        }

        handleScroll();
        window.addEventListener('scroll', handleScroll, { passive: true });

        return () => window.removeEventListener('scroll', handleScroll);
    }, []);

    return (
        <Button
            type="button"
            variant="outline"
            size="sm"
            className={cn(
                'fixed right-6 bottom-6 z-50 shadow-md transition-all duration-300',
                visible
                    ? 'translate-y-0 opacity-100'
                    : 'pointer-events-none translate-y-2 opacity-0',
            )}
            onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })}
        >
            <ArrowUp className="size-4" />
            Back to top
        </Button>
    );
}

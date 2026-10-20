import { Download } from 'lucide-react';
import ResumeController from '@/actions/App/Http/Controllers/ResumeController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { Resume } from '@/types';

/** Shows a resume in place: PDFs in the browser's viewer, other formats as plain text. */
export default function ResumePreviewDialog({
    resume,
    onOpenChange,
}: {
    resume: Resume | null;
    onOpenChange: (open: boolean) => void;
}) {
    const isPdf = resume?.original_filename.toLowerCase().endsWith('.pdf');
    const isDocx = resume?.original_filename.toLowerCase().endsWith('.docx');

    return (
        <Dialog open={resume !== null} onOpenChange={onOpenChange}>
            <DialogContent className="flex h-[90vh] max-w-5xl flex-col gap-3 sm:max-w-5xl">
                {resume && (
                    <>
                        <DialogHeader className="flex flex-row items-start justify-between gap-4 pr-8">
                            <div className="grid gap-1">
                                <DialogTitle>{resume.label}</DialogTitle>
                                <DialogDescription>
                                    {resume.original_filename}
                                    {isDocx &&
                                        ' · text preview, formatting not shown. Download to see the original.'}
                                </DialogDescription>
                            </div>
                            <Button variant="outline" size="sm" asChild>
                                <a
                                    href={ResumeController.download.url(
                                        resume.id,
                                    )}
                                >
                                    <Download /> Download
                                </a>
                            </Button>
                        </DialogHeader>
                        <iframe
                            key={resume.id}
                            src={ResumeController.preview.url(resume.id)}
                            title={`Preview of ${resume.label}`}
                            className={`min-h-0 w-full flex-1 rounded-md border ${isPdf ? '' : 'bg-white'}`}
                        />
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

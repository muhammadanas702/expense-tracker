package pk.expenseflow.app;

import android.app.job.JobParameters;
import android.app.job.JobService;

/** Runs every ~15 minutes (see AlertPoller.schedule): fetches new alerts off the main thread. */
public class AlertJob extends JobService {
    @Override
    public boolean onStartJob(final JobParameters params) {
        new Thread(new Runnable() {
            @Override public void run() {
                try { AlertPoller.poll(getApplicationContext()); } finally { jobFinished(params, false); }
            }
        }).start();
        return true;
    }

    @Override
    public boolean onStopJob(JobParameters params) { return true; }
}

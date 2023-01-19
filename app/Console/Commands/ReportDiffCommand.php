<?php

namespace App\Console\Commands;

use Aws\Sts\StsClient;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class ReportDiffCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'config:report-diff {start : First date to compare} {end? : Last date to compare, defaults to today} {--resource-types-only : Return a list of resource types involved instead of the diff}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reports the diff between two dates';

    protected $start;
    protected $end;

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->start = Carbon::parse($this->argument('start'));
        $this->end = Carbon::parse($this->argument('end', now()));

        $data = $this->retrieveData();
        if ($this->options('resource-types-only')) {
            $this->printTypes($data);
        } else {
            $this->printResult($data);
        }

        return Command::SUCCESS;
    }

    protected function retrieveData()
    {
        $accountId = $this->getAccountId();
        $path = 'AWSLogs/'.$accountId.'/Config/'.config('filesystems.disks.s3.region');
        if (config('aws-config.prefix')) {
            $path = config('aws-config.prefix').'/'.$path;
        }
        $startPath = $path.'/'.$this->start->year.'/'.$this->start->month.'/'.$this->start->day.'/ConfigSnapshot/';
        $endPath = $path.'/'.$this->end->year.'/'.$this->end->month.'/'.$this->end->day.'/ConfigSnapshot/';
        $startFile = collect(Storage::files($startPath))->first();
        $endFile = collect(Storage::files($endPath))->first();
        $this->line('Loading old configuration');
        $data = collect(Arr::get(json_decode(Storage::get($startFile), true), 'configurationItems'))
            ->keyBy('resourceId')
            ->transform(function ($item) {
                return [
                    'old'=>Arr::dot($item),
                    'new'=>null,
                ];
            });
        $this->line('Loading new configuration');
        collect(Arr::get(json_decode(Storage::get($endFile), true), 'configurationItems'))
            ->each(function ($item) use ($data) {
                $id = Arr::get($item, 'resourceId');
                if ($data->has($id)) {
                    $update = $data->get($id);
                    $update['new'] = Arr::dot($item);
                    $data->put($id, $update);
                } else {
                    $data->put($id, [
                        'old'=>null,
                        'new'=>Arr::dot($item),
                    ]);
                }
            });
        $this->line('Removing equal items');
        $data = $data->transform(function ($pair, $id) {
            $created = false;
            $deleted = false;
            $old = collect(Arr::get($pair, 'old'));
            if (!$old->count()) {
                $created = true;
            }
            $new = collect(Arr::get($pair, 'new'));
            if (!$new->count()) {
                $deleted = true;
            }
            $type = $old->get('resourceType', $new->get('resourceType'));
            $old = $old->transform(function ($value, $key) use ($new) {
                if ($new->get($key) == $value) {
                    $new->forget($key);
                    return null;
                } else {
                    return $value;
                }
            })->filter(function ($value) {
                return $value;
            });
            $keys = collect($old->keys()->merge($new->keys()));
            $changes = collect();
            $keys->each(function ($key) use ($old, $new, $changes) {
                $changes->put($key, [
                    'old'=>$old->get($key),
                    'new'=>$new->get($key),
                ]);
            });

            return [
                'id'=>$id,
                'type'=>$type,
                'created'=>$created,
                'deleted'=>$deleted,
                'changes'=>$changes,
            ];
        })->filter(function ($item) {
            return Arr::get($item, 'changes', collect())->count() > 0;
        });
        return $data;
    }

    protected function getAccountId()
    {
        $client = new StsClient([
            'version'=>'latest',
            'region'=>config('filesystems.disks.s3.region'),
        ]);
        return $client->getCallerIdentity()->get('Account');
    }

    protected function printResult($data)
    {
        $data->each(function ($item) {
            $this->line('Resource '.Arr::get($item, 'id').' : Type '.Arr::get($item, 'type'));
            if (Arr::get($item, 'created')) {
                $this->comment($this->start->format("Y-m-d").' - Did not exist');
                $this->info($this->end->format("Y-m-d"). ' + Exists');
            } elseif (Arr::get($item, 'deleted')) {
                $this->comment($this->start->format("Y-m-d").' - Existed');
                $this->info($this->end->format("Y-m-d"). ' + No longer exists');
            } else {
                $this->line('---------------');
                Arr::get($item, 'changes')->each(function ($change, $key) {
                    $this->comment($this->start->format("Y-m-d").' - '.$key.' : '.(Arr::get($change, 'old') ?: 'N/A'));
                    $this->info($this->end->format("Y-m-d").' + '.$key.' : '.(Arr::get($change, 'new') ?: 'N/A'));
                    $this->line('---------------');
                });
            }
            $this->newline();
        });
    }

    protected function printTypes($data)
    {
        $this->line('Resource types that have been changed between '.$this->start->format("YYYY-MM-DD").' and '.$this->end->format("YYYY-MM-DD").':');
        $types = $data->pluck('type')->unique();
        $types->each(function ($type) {
            $this->info($type);
        });
    }
}

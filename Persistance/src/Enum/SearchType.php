<?php

namespace App\Enum;

enum SearchType: string
{

    case AverageMetricOnDay = 'average_metric_on_day';

    case ThresholdCrossing = 'threshold_crossing';

    case BucketAverage = 'bucket_average';

    case RawRange = 'raw_range';
}

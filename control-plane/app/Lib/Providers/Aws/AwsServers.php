<?php

declare(strict_types=1);

namespace App\Lib\Providers\Aws;

use App\Lib\Providers\ProviderServer;
use App\Lib\Providers\Rejected;
use App\Lib\Providers\ServerDriver;
use App\Lib\Providers\ServerSpec;
use App\Lib\Providers\Uncertain;
use SimpleXMLElement;

/**
 * EC2 machines. One security group per region opens 80 and 443 and nothing
 * else: no SSH, because the agent dials out. Tags are keys with empty values
 * (plus a Name), so listTagged is a tag-key filter.
 *
 * Idempotency is RunInstances' ClientToken: a retried create returns the same
 * instance. The user-data limit is 16 KB; cloud-init is checked against it in
 * the tests.
 */
final class AwsServers implements ServerDriver
{
    public const GROUP = 'cloud-manager';

    public const USER_DATA_LIMIT = 16384;

    public function __construct(private readonly AwsClient $aws, private readonly ?string $ami = null) {}

    public function create(ServerSpec $spec): string
    {
        if (strlen($spec->userData) > self::USER_DATA_LIMIT) {
            throw new Rejected('The bootstrap script is larger than EC2 allows ('.self::USER_DATA_LIMIT.' bytes of user data).');
        }

        $aws = $this->aws->region() === $spec->region || $spec->region === '' ? $this->aws : $this->aws->inRegion($spec->region);

        $params = [
            'ImageId' => $this->image($aws, $spec->image),
            'InstanceType' => $spec->size,
            'MinCount' => 1,
            'MaxCount' => 1,
            'ClientToken' => $spec->idempotencyKey,
            'UserData' => base64_encode($spec->userData),
            'SecurityGroupId.1' => $this->securityGroup($aws),
            'TagSpecification.1.ResourceType' => 'instance',
            'TagSpecification.1.Tag.1.Key' => 'Name',
            'TagSpecification.1.Tag.1.Value' => $spec->name,
        ];
        foreach (array_values($spec->tags) as $i => $tag) {
            $params['TagSpecification.1.Tag.'.($i + 2).'.Key'] = $tag;
            $params['TagSpecification.1.Tag.'.($i + 2).'.Value'] = '';
        }

        $id = (string) ($aws->ec2('RunInstances', $params)->instancesSet->item->instanceId ?? '');

        return $id !== '' ? $id : throw new Uncertain('AWS accepted the create but returned no instance id.');
    }

    public function find(string $externalId): ?ProviderServer
    {
        try {
            $items = $this->aws->ec2('DescribeInstances', ['InstanceId.1' => $externalId])->reservationSet->item ?? null;
        } catch (Rejected $e) {
            if (str_contains($e->getMessage(), 'InvalidInstanceID.NotFound')) {
                return null;
            }
            throw new Uncertain($e->getMessage(), 0, $e);
        }
        $instance = $items?->instancesSet->item ?? null;

        return $instance === null ? null : $this->server($instance);
    }

    public function destroy(string $externalId): void
    {
        try {
            $this->aws->ec2('TerminateInstances', ['InstanceId.1' => $externalId]);
        } catch (Rejected $e) {
            if (! str_contains($e->getMessage(), 'InvalidInstanceID.NotFound')) {
                throw new Uncertain($e->getMessage(), 0, $e);
            }
        }
    }

    public function listTagged(string $tag): array
    {
        try {
            $xml = $this->aws->ec2('DescribeInstances', [
                'Filter.1.Name' => 'tag-key', 'Filter.1.Value.1' => $tag,
                'Filter.2.Name' => 'instance-state-name', 'Filter.2.Value.1' => 'pending', 'Filter.2.Value.2' => 'running',
                'Filter.2.Value.3' => 'stopping', 'Filter.2.Value.4' => 'stopped',
            ]);
        } catch (Rejected $e) {
            throw new Uncertain($e->getMessage(), 0, $e);
        }

        $servers = [];
        foreach ($xml->reservationSet->item ?? [] as $reservation) {
            foreach ($reservation->instancesSet->item ?? [] as $instance) {
                $servers[] = $this->server($instance);
            }
        }

        return $servers;
    }

    /** A catalog name ('ubuntu-24.04') becomes the current AMI; an ami-… id is used as is. */
    private function image(AwsClient $aws, string $image): string
    {
        if (str_starts_with($image, 'ami-')) {
            return $image;
        }

        return $this->ami ?: $aws->ssmParameter(AwsProvider::AMI_PARAMETER);
    }

    /** The region's security group for our servers, made on first use. */
    private function securityGroup(AwsClient $aws): string
    {
        $found = $aws->ec2('DescribeSecurityGroups', ['Filter.1.Name' => 'group-name', 'Filter.1.Value.1' => self::GROUP]);
        $id = (string) ($found->securityGroupInfo->item->groupId ?? '');
        if ($id !== '') {
            return $id;
        }

        $id = (string) $aws->ec2('CreateSecurityGroup', ['GroupName' => self::GROUP, 'GroupDescription' => 'cloud-manager servers: web only, the agent dials out'])->groupId;
        $aws->ec2('AuthorizeSecurityGroupIngress', [
            'GroupId' => $id,
            'IpPermissions.1.IpProtocol' => 'tcp', 'IpPermissions.1.FromPort' => 80, 'IpPermissions.1.ToPort' => 80, 'IpPermissions.1.IpRanges.1.CidrIp' => '0.0.0.0/0',
            'IpPermissions.2.IpProtocol' => 'tcp', 'IpPermissions.2.FromPort' => 443, 'IpPermissions.2.ToPort' => 443, 'IpPermissions.2.IpRanges.1.CidrIp' => '0.0.0.0/0',
        ]);

        return $id;
    }

    private function server(SimpleXMLElement $i): ProviderServer
    {
        $name = '';
        foreach ($i->tagSet->item ?? [] as $tag) {
            if ((string) $tag->key === 'Name') {
                $name = (string) $tag->value;
            }
        }

        return new ProviderServer(
            id: (string) $i->instanceId,
            state: match ((string) $i->instanceState->name) {
                'pending' => 'pending',
                'running' => 'running',
                'shutting-down', 'terminated' => 'gone',
                default => 'stopped',
            },
            ipv4: ((string) $i->ipAddress) ?: null,
            privateIpv4: ((string) $i->privateIpAddress) ?: null,
            name: $name,
        );
    }
}

<?php
namespace Omeka\Api\Adapter;

use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Exception;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\Entity\ResourceClass;
use Omeka\Stdlib\ErrorStore;

class ItemAdapter extends AbstractResourceEntityAdapter
{
    /**
     * {@inheritDoc}
     */
    protected $sortFields = array(
        'id'           => 'id',
        'is_public'    => 'isPublic',
        'created'      => 'created',
        'modified'     => 'modified',
    );

    /**
     * {@inheritDoc}
     */
    public function getResourceName()
    {
        return 'items';
    }

    /**
     * {@inheritDoc}
     */
    public function getRepresentationClass()
    {
        return 'Omeka\Api\Representation\ItemRepresentation';
    }

    /**
     * {@inheritDoc}
     */
    public function getEntityClass()
    {
        return 'Omeka\Entity\Item';
    }

    /**
     * {@inheritDoc}
     */
    public function buildQuery(QueryBuilder $qb, array $query)
    {
        parent::buildQuery($qb, $query);

        if (isset($query['item_set_id']) && is_numeric($query['item_set_id'])) {
            $itemSetAlias = $this->createAlias();
            $qb->innerJoin(
                $this->getEntityClass() . '.itemSets',
                $itemSetAlias
            );
            $qb->andWhere($qb->expr()->eq(
                "$itemSetAlias.id",
                $this->createNamedParameter($qb, $query['item_set_id']))
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function validateRequest(Request $request, ErrorStore $errorStore)
    {
        $data = $request->getContent();
        if (array_key_exists('o:item_set', $data)
            && !is_array($data['o:item_set'])
        ) {
            $errorStore->addError('o:item_set', 'Item sets must be an array');
        }

        if (array_key_exists('o:media', $data)
            && !is_array($data['o:media'])
        ) {
            $errorStore->addError('o:item_set', 'Media must be an array');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function hydrate(Request $request, EntityInterface $entity,
        ErrorStore $errorStore
    ) {
        parent::hydrate($request, $entity, $errorStore);

        if ($this->shouldHydrate($request, 'o:item_set')) {
            $itemSetsData = $request->getValue('o:item_set', array());

            $itemSetAdapter = $this->getAdapter('item_sets');
            $itemSets = $entity->getItemSets();
            $itemSetsToRetain = array();

            foreach ($itemSetsData as $itemSetData) {
                if (is_array($itemSetData)
                    && array_key_exists('o:id', $itemSetData)
                    && is_numeric($itemSetData['o:id'])
                ) {
                    $itemSetId = $itemSetData['o:id'];
                } elseif (is_numeric($itemSetData)) {
                    $itemSetId = $itemSetData;
                } else {
                    continue;
                }

                if (!$itemSet = $itemSets->get($itemSetId)) {
                    // Item set not already assigned. Assign it.
                    $itemSet = $itemSetAdapter->findEntity($itemSetId);
                    $itemSets->add($itemSet);
                }

                $itemSetsToRetain[] = $itemSet;
            }

            // Unassign item sets that were not included in the passed data.
            foreach ($itemSets as $itemSet) {
                if (!in_array($itemSet, $itemSetsToRetain)) {
                    $itemSets->removeElement($itemSet);
                }
            }
        }

        if ($this->shouldHydrate($request, 'o:media')) {
            $mediasData = $request->getValue('o:media', array());
            $adapter = $this->getAdapter('media');
            $class = $adapter->getEntityClass();
            $retainMedia = array();
            $position = 1;
            foreach ($mediasData as $mediaData) {
                $subErrorStore = new ErrorStore;
                if (isset($mediaData['o:id'])) {
                    $media = $adapter->findEntity($mediaData['o:id']);
                    $media->setPosition($position);
                    if (isset($mediaData['o:is_public'])) {
                        $media->setIsPublic($mediaData['o:is_public']);
                    }
                    $retainMedia[] = $media;
                } else {
                    // Create a new media.
                    $media = new $class;
                    $media->setItem($entity);
                    $media->setPosition($position);
                    $subrequest = new Request(Request::CREATE, 'media');
                    $subrequest->setContent($mediaData);
                    $subrequest->setFileData($request->getFileData());
                    try {
                        $adapter->hydrateEntity($subrequest, $media, $subErrorStore);
                    } catch (Exception\ValidationException $e) {
                        $errorStore->mergeErrors($e->getErrorStore(), 'o:media');
                    }
                    $entity->getMedia()->add($media);
                    $retainMedia[] = $media;
                }
                $position++;
            }
            // Remove media not included in request.
            foreach ($entity->getMedia() as $media) {
                if (!in_array($media, $retainMedia, true)) {
                    $entity->getMedia()->removeElement($media);
                }
            }
        }
    }
}

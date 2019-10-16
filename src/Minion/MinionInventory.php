<?php
declare(strict_types=1);
namespace Minion;

use pocketmine\block\BlockLegacyIds;
use pocketmine\inventory\BaseInventory;
use pocketmine\inventory\BlockInventory;
use pocketmine\item\ItemFactory;
use pocketmine\item\ItemIds;
use pocketmine\math\Vector3;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\ContainerClosePacket;
use pocketmine\network\mcpe\protocol\ContainerOpenPacket;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use pocketmine\network\mcpe\protocol\types\RuntimeBlockMapping;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\player\Player;
use pocketmine\world\Position;

class MinionInventory extends BlockInventory{

	/** @var Vector3|null */
	protected $vector;

	protected $minion;

	public function __construct(Minion $holder){
		parent::__construct(new Position(), 27);
		$this->minion = $holder;
	}

	public function onOpen(Player $who) : void{
		BaseInventory::onOpen($who);

		$this->setVector3($who->getPosition()->add(0, 3)->floor());

		$x = $this->getVector3()->getX();
		$y = $this->getVector3()->getY();
		$z = $this->getVector3()->getZ();

		$pk = new UpdateBlockPacket();
		$pk->blockRuntimeId = RuntimeBlockMapping::toStaticRuntimeId(BlockLegacyIds::CHEST);
		$pk->x = $x;
		$pk->y = $y;
		$pk->z = $z;
		$who->getNetworkSession()->sendDataPacket($pk);

		$pk = new BlockActorDataPacket();
		$pk->x = $x;
		$pk->y = $y;
		$pk->z = $z;
		$pk->namedtag = (new LittleEndianNbtSerializer())->write(new TreeRoot(CompoundTag::create()
			->setString("CustomName", "Minion")
			->setInt("x", $x)
			->setInt("y", $y)
			->setInt("z", $z)
		));
		$who->getNetworkSession()->sendDataPacket($pk);

		$pk = new ContainerOpenPacket();
		$pk->x = $x;
		$pk->y = $y;
		$pk->z = $z;
		$pk->windowId = $who->getNetworkSession()->getInvManager()->getWindowId($this);
		$pk->type = WindowTypes::CONTAINER;
		$who->getNetworkSession()->sendDataPacket($pk);
		
		$restore = ItemFactory::get(ItemIds::STAINED_GLASS_PANE, 14);//RED STAINED GLASS

		$restore->setCustomName("미니언 회수하기");

		$restore->setLore([
			"미니언 생성하기",
			"",
			"미니언을 생성하시려면, 생성하려는 위치에 미니언 블럭을 터치해주세요!"
		]);

		$restore->getNamedTag()->setString("restore", "s");

		$this->setItem(26, $restore);

		$who->getNetworkSession()->getInvManager()->syncContents($this);
	}

	public function onClose(Player $who) : void{
		BaseInventory::onClose($who);

		if($this->getVector3() !== null){
			$x = $this->getVector3()->getX();
			$y = $this->getVector3()->getY();
			$z = $this->getVector3()->getZ();
		}else{
			$x = $who->getPosition()->add(0, 3)->floor()->getX();
			$y = $who->getPosition()->add(0, 3)->floor()->getY();
			$z = $who->getPosition()->add(0, 3)->floor()->getZ();
		}

		$block = $who->getWorld()->getBlock(new Vector3($x, $y, $z));

		$pk = new UpdateBlockPacket();
		$pk->blockRuntimeId = RuntimeBlockMapping::toStaticRuntimeId($block->getId(), $block->getMeta());
		$pk->x = $x;
		$pk->y = $y;
		$pk->z = $z;
		$who->getNetworkSession()->sendDataPacket($pk);

		$pk = new ContainerClosePacket();
		$pk->windowId = $who->getNetworkSession()->getInvManager()->getWindowId($this);
		$who->getNetworkSession()->sendDataPacket($pk);

		$this->vector = null;
	}

	public function getVector3() : ?Vector3{
		return $this->vector;
	}

	public function setVector3(?Vector3 $pos){
		$this->vector = $pos;
	}

	public function getMinion() : Minion{
		return $this->minion;
	}
}
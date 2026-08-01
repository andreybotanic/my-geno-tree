import {
  BaseEdge,
  getSmoothStepPath,
  type EdgeProps,
} from '@xyflow/react'
import type {
  FamilyFlowEdge,
  SingleParentFlowEdge,
} from '../treeGraph'

const CARD_CONNECTION_OVERLAP = 5
const PAIR_CORNER_RADIUS = 8

export function FamilyEdge({
  data,
  id,
  sourceX,
  sourceY,
  targetX,
  targetY,
}: EdgeProps<FamilyFlowEdge>) {
  const sourceExitX = sourceX + (data?.sourceOffset ?? 0)
  const targetExitX = targetX + (data?.targetOffset ?? 0)
  const pairY = Math.max(sourceY, targetY) + (data?.bracketDepth ?? 24)
  const pairPath = [
    `M ${sourceExitX} ${sourceY - CARD_CONNECTION_OVERLAP}`,
    `L ${sourceExitX} ${pairY - PAIR_CORNER_RADIUS}`,
    `Q ${sourceExitX} ${pairY} ${sourceExitX + PAIR_CORNER_RADIUS} ${pairY}`,
    `L ${targetExitX - PAIR_CORNER_RADIUS} ${pairY}`,
    `Q ${targetExitX} ${pairY} ${targetExitX} ${pairY - PAIR_CORNER_RADIUS}`,
    `L ${targetExitX} ${targetY - CARD_CONNECTION_OVERLAP}`,
  ].join(' ')
  const children = data?.childPoints ?? []
  const childPath = childrenPath(
    (sourceX + targetX) / 2,
    pairY,
    children,
  )
  const className = data?.married
    ? 'tree-edge tree-edge-marriage'
    : 'tree-edge tree-edge-coparent'

  return (
    <>
      <BaseEdge className={className} id={id} path={pairPath} />
      {childPath && (
        <path className="tree-edge tree-edge-child" d={childPath} />
      )}
    </>
  )
}

export function SingleParentEdge({
  id,
  sourcePosition,
  sourceX,
  sourceY,
  targetPosition,
  targetX,
  targetY,
}: EdgeProps<SingleParentFlowEdge>) {
  const [path] = getSmoothStepPath({
    borderRadius: 10,
    offset: 30,
    sourcePosition,
    sourceX,
    sourceY,
    targetPosition,
    targetX,
    targetY,
  })

  return (
    <BaseEdge
      className="tree-edge tree-edge-child"
      id={id}
      path={path}
    />
  )
}

function childrenPath(
  familyX: number,
  familyY: number,
  children: Array<{ topX: number; topY: number }>,
) {
  if (children.length === 0) {
    return ''
  }
  const bracketY = Math.min(...children.map((child) => child.topY)) - 28
  if (children.length === 1) {
    const child = children[0]
    if (!child) {
      return ''
    }
    return childBranchPath(familyX, familyY, bracketY, child)
  }

  const first = children[0]
  const last = children[children.length - 1]
  if (!first || !last) {
    return ''
  }
  const directChildren = children.filter((child, index) => (
    child.topX === familyX || (index > 0 && index < children.length - 1)
  ))
  return [
    `M ${familyX} ${familyY}`,
    `L ${familyX} ${bracketY}`,
    ...directChildren.map((child) => (
      `M ${child.topX} ${bracketY} L ${child.topX} ${child.topY}`
    )),
    ...(first.topX !== familyX
      ? [bracketBranchPath(familyX, bracketY, first)]
      : []),
    ...(last.topX !== familyX
      ? [bracketBranchPath(familyX, bracketY, last)]
      : []),
  ].join(' ')
}

function childBranchPath(
  familyX: number,
  familyY: number,
  bracketY: number,
  child: { topX: number; topY: number },
) {
  const direction = Math.sign(child.topX - familyX)
  if (direction === 0) {
    return [
      `M ${familyX} ${familyY}`,
      `L ${child.topX} ${child.topY}`,
    ].join(' ')
  }
  const radius = Math.min(
    PAIR_CORNER_RADIUS,
    Math.abs(child.topX - familyX) / 2,
    Math.abs(child.topY - bracketY) / 2,
  )

  return [
    `M ${familyX} ${familyY}`,
    `L ${familyX} ${bracketY - radius}`,
    `Q ${familyX} ${bracketY} ${familyX + direction * radius} ${bracketY}`,
    `L ${child.topX - direction * radius} ${bracketY}`,
    `Q ${child.topX} ${bracketY} ${child.topX} ${bracketY + radius}`,
    `L ${child.topX} ${child.topY}`,
  ].join(' ')
}

function bracketBranchPath(
  familyX: number,
  bracketY: number,
  child: { topX: number; topY: number },
) {
  const direction = Math.sign(child.topX - familyX)
  const radius = Math.min(
    PAIR_CORNER_RADIUS,
    Math.abs(child.topX - familyX) / 2,
    Math.abs(child.topY - bracketY) / 2,
  )

  return [
    `M ${familyX} ${bracketY}`,
    `L ${child.topX - direction * radius} ${bracketY}`,
    `Q ${child.topX} ${bracketY} ${child.topX} ${bracketY + radius}`,
    `L ${child.topX} ${child.topY}`,
  ].join(' ')
}

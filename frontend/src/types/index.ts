export interface AppInfo {
  name: string
  tagline: string
  version: string
  environment: string
  apiBaseUrl: string
}

export interface TechStackItem {
  name: string
  category: string
  version: string
  status: 'Ready' | 'Configured' | 'Scaffold'
  notes: string
}

export interface ApiHealthResponse {
  status: string
  service?: string
  message?: string
  timestamp: string
}

export * from './auth'
export * from './catalog'
export * from './creator'
export * from './admin'
export * from './userLibrary'
export * from './readingHistory'
export * from './chat'
export type {
  Post,
  PostAuthor,
  PostComic,
  CreatePostPayload,
  UpdatePostPayload,
  LikeData,
  LikeResponse,
  PostSortOption,
  ListPostsParams,
  CommentableType,
  CommentAuthor,
  CommentItemData,
  CommentListResponse,
  CreateCommentPayload,
  ReplyCommentPayload,
  ListCommentsParams,
} from './community'
